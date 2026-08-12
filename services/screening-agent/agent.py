"""
The voice on the screening call.

Laravel decides *who* to ring and *what to say*; this service holds the actual
conversation and reports back. It never touches the database — everything it
needs arrives as job metadata from App\\Services\\Screening\\LiveKitVoiceAgent,
and everything it learns goes back through one signed webhook.

Flow for a single call:

    dispatch (metadata) -> dial the worker -> wait for them to answer
      -> talk -> extract a structured result -> POST it to Laravel

Kept deliberately thin. Retries, calling hours, who may be called and what
happens to the answer all live in Laravel, so this file has no policy in it.
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
from typing import Any

import aiohttp
from dotenv import load_dotenv
from livekit import agents, api
from livekit.agents import Agent, AgentSession, JobContext, WorkerOptions, cli

load_dotenv()

logger = logging.getLogger("screening-agent")

# How long to wait for someone to pick up. Beyond this the carrier has almost
# always given up too, and Laravel will schedule the retry.
ANSWER_TIMEOUT = float(os.getenv("SCREENING_ANSWER_TIMEOUT", "45"))

WEBHOOK_URL = os.environ["SCREENING_WEBHOOK_URL"]
WEBHOOK_SECRET = os.environ["SCREENING_WEBHOOK_SECRET"]

# Models. Plain "provider/model" strings run on LiveKit Inference — no separate
# accounts or keys. Swap TTS to a Sarvam plugin here if the Indic voices win the
# listening test; nothing else in this file changes.
STT_MODEL = os.getenv("SCREENING_STT", "deepgram/nova-3")
TTS_MODEL = os.getenv("SCREENING_TTS", "cartesia/sonic-3")
LLM_MODEL = os.getenv("SCREENING_LLM", "openai/gpt-4o-mini")


async def entrypoint(ctx: JobContext) -> None:
    meta: dict[str, Any] = json.loads(ctx.job.metadata or "{}")
    dial = meta["dial"]
    call_id = meta["room"]

    await ctx.connect()

    # Dial from here rather than from Laravel so the agent is already in the
    # room when the worker answers. Dialling first would let them pick up to
    # silence, and a karigar hangs up on silence within two seconds.
    try:
        await ctx.api.sip.create_sip_participant(
            api.CreateSIPParticipantRequest(
                room_name=meta["room"],
                sip_trunk_id=dial["sip_trunk_id"],
                sip_call_to=dial["sip_call_to"],
                participant_identity=dial["participant_identity"],
                participant_name=dial["participant_name"],
                krisp_enabled=dial.get("krisp_enabled", True),
                wait_until_answered=dial.get("wait_until_answered", True),
                play_dialtone=dial.get("play_dialtone", False),
            )
        )
    except api.TwirpError as e:
        # SIP status tells us *why*: busy, rejected, unallocated number. Laravel
        # decides whether that earns a retry.
        await report(
            call_id,
            status="failed",
            failure_reason=e.metadata.get("sip_status", e.message) if e.metadata else e.message,
        )
        ctx.shutdown()
        return

    try:
        participant = await asyncio.wait_for(
            ctx.wait_for_participant(identity=dial["participant_identity"]),
            timeout=ANSWER_TIMEOUT,
        )
    except asyncio.TimeoutError:
        await report(call_id, status="no_answer", failure_reason="answer_timeout")
        ctx.shutdown()
        return

    logger.info("worker answered", extra={"call_id": call_id, "participant": participant.identity})

    session = AgentSession(stt=STT_MODEL, llm=LLM_MODEL, tts=TTS_MODEL)

    await session.start(
        room=ctx.room,
        agent=Agent(instructions=meta["instructions"]),
    )

    # The fixed opening line. Said, not generated, so every worker hears the
    # same disclosure of who is calling and why.
    await session.say(meta["greeting"], allow_interruptions=True)

    # Hold the call open until the far end goes away. The instructions tell the
    # agent to end the conversation once it has what it came for.
    disconnected = asyncio.Event()
    ctx.room.on("participant_disconnected", lambda _: disconnected.set())
    await disconnected.wait()

    await report(call_id, **await extract(session, meta))


async def extract(session: AgentSession, meta: dict[str, Any]) -> dict[str, Any]:
    """
    Turn the conversation into the fields Laravel stores.

    Done in a second pass over the finished transcript rather than mid-call:
    the agent's job during the call is to sound like a person, and asking it to
    emit JSON while talking makes it stilted.
    """
    transcript = "\n".join(
        f"{item.role}: {item.text_content}"
        for item in session.history.items
        if getattr(item, "text_content", None)
    )

    if not transcript.strip():
        return {"status": "failed", "failure_reason": "empty_transcript"}

    schema = json.dumps(meta["extraction_schema"], ensure_ascii=False)

    prompt = (
        "Read this screening call transcript and return ONLY a JSON object "
        f"matching this schema:\n{schema}\n\n"
        "Times must be IST in 'YYYY-MM-DD HH:MM:SS' form. Use null for anything "
        "the worker did not actually say — never guess a time.\n\n"
        f"Transcript:\n{transcript}"
    )

    try:
        answer = await session.llm.chat(chat_ctx=agents.llm.ChatContext().append(text=prompt))
        fields = json.loads(_strip_fence(answer.content))
    except Exception:
        # A call that happened but could not be parsed is still a completed
        # call — the employer can read the transcript and decide themselves.
        logger.exception("extraction failed", extra={"call_id": meta["room"]})
        return {"status": "completed", "transcript": transcript}

    return {
        "status": "completed",
        "outcome": fields.get("outcome"),
        "proposed_interview_at": fields.get("proposed_interview_at"),
        "proposed_mode": fields.get("proposed_mode"),
        "summary": fields.get("summary"),
        "transcript": transcript,
    }


def _strip_fence(text: str) -> str:
    """Models wrap JSON in ```json fences often enough to handle it here."""
    cleaned = text.strip()

    if cleaned.startswith("```"):
        cleaned = cleaned.split("\n", 1)[1].rsplit("```", 1)[0]

    return cleaned.strip()


async def report(call_id: str, **fields: Any) -> None:
    """
    Hand the result to Laravel. Fire and forget is not good enough — if this
    POST is lost the call row stays stuck in 'dialing' forever, so it retries.
    """
    payload = {"call_id": call_id, **{k: v for k, v in fields.items() if v is not None}}

    async with aiohttp.ClientSession() as http:
        for attempt in range(3):
            try:
                async with http.post(
                    WEBHOOK_URL,
                    json=payload,
                    headers={"X-Screening-Signature": WEBHOOK_SECRET},
                    timeout=aiohttp.ClientTimeout(total=10),
                ) as response:
                    if response.status < 300:
                        return

                    logger.error("webhook rejected %s: %s", response.status, await response.text())
            except aiohttp.ClientError:
                logger.exception("webhook unreachable")

            await asyncio.sleep(2**attempt)


if __name__ == "__main__":
    cli.run_app(
        WorkerOptions(
            entrypoint_fnc=entrypoint,
            # Must match LIVEKIT_AGENT_NAME in Laravel, or dispatches go
            # nowhere and every call silently times out.
            agent_name=os.getenv("LIVEKIT_AGENT_NAME", "screening-agent"),
        )
    )
