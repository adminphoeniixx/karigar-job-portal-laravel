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
from livekit.agents import Agent, AgentSession, JobContext, WorkerOptions, cli, inference
from livekit.agents import llm as llm_module, stt as stt_module, tts as tts_module

# Imported here, at module level, and not inside the builders where they are
# actually used: LiveKit registers a plugin the moment it is imported, and it
# refuses a registration that does not come from the main thread. Importing
# lazily puts it on the job task instead, and the call dies before the first
# word with "Plugins must be registered on the main thread".
#
# Absent on a LiveKit Cloud deployment, which needs no vendor plugins at all —
# hence the guard rather than a plain import.
try:
    from livekit.plugins import cartesia, deepgram, elevenlabs, inworld, openai, sarvam
except ImportError:  # pragma: no cover - depends on how the image was built
    cartesia = deepgram = elevenlabs = inworld = openai = sarvam = None  # type: ignore[assignment]

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

# Where speech and the LLM come from:
#
#   "inference" — LiveKit's gateway. One account, no vendor keys. Only works on
#                 LiveKit Cloud: the gateway checks that the room belongs to the
#                 project and answers a self-hosted job with 401.
#   "plugins"   — each vendor called directly with our own key. This is what a
#                 self-hosted deployment must use.
#
# The model strings are the same either way ("provider/model"), so switching
# hosting does not mean rewriting the config.
SPEECH_BACKEND = os.getenv("SCREENING_SPEECH", "inference")

# Optional LiveKit Cloud project credentials, when they differ from the media
# server's (only meaningful on the "inference" backend).
INFERENCE_KEY = os.getenv("LIVEKIT_INFERENCE_API_KEY")
INFERENCE_SECRET = os.getenv("LIVEKIT_INFERENCE_API_SECRET")

INFERENCE_AUTH: dict[str, str] = (
    {"api_key": INFERENCE_KEY, "api_secret": INFERENCE_SECRET}
    if INFERENCE_KEY and INFERENCE_SECRET
    else {}
)

# An OpenAI-compatible endpoint for the LLM. The rest of the platform already
# runs its AI on DigitalOcean's Llama, and that same account works here — one
# less vendor to sign up with when self-hosting.
LLM_BASE_URL = os.getenv("SCREENING_LLM_BASE_URL")
LLM_API_KEY = os.getenv("SCREENING_LLM_API_KEY")

# What to tell the STT it is listening to. Hindi calls are never pure Hindi —
# "kal subah 10 baje site pe aa jaunga" is one sentence in two languages — so
# they go to the multilingual model rather than to `hi`, which drops the English
# words. Everything else passes its own code straight through.
STT_LANGUAGES = {"hi": "multi", "en": "multi"}


def load_metadata(ctx: JobContext) -> dict[str, Any]:
    """
    The script for this call. Normally it arrives as job metadata from Laravel.

    In rehearsal it comes from a file instead (`php artisan screening:rehearse`
    writes it), which is what makes it possible to test the script, the voice
    and the extraction with no carrier and no phone line — the one part of this
    feature that cannot be bought on a free trial.
    """
    raw = ctx.job.metadata if ctx.job else None

    if raw:
        return json.loads(raw)

    path = os.getenv("SCREENING_TEST_SCRIPT")

    if not path:
        raise RuntimeError(
            "No job metadata and no SCREENING_TEST_SCRIPT set. "
            "Run `php artisan screening:rehearse <application-id>` first."
        )

    with open(path, encoding="utf-8") as handle:
        return json.load(handle)


def split_model(spec: str) -> tuple[str, str]:
    """"deepgram/nova-3" -> ("deepgram", "nova-3"). No slash means no vendor."""
    provider, _, model = spec.partition("/")

    return provider, model or provider


def build_stt(meta: dict[str, Any]) -> stt_module.STT:
    """
    Speech recognition, told which language it is listening to.

    This is not optional decoration. Left at its default the model listens for
    English, and a worker saying "haan sir, parso subah gyarah baje aa jaunga"
    comes back as nonsense — which then poisons the LLM's reply and the
    extraction after it. Deepgram's multilingual code handles the code-switching
    a karigar actually does (Hindi sentence, English words for time and money).
    """
    language = meta.get("language", "hi")

    if SPEECH_BACKEND == "inference":
        return inference.STT(
            model=STT_MODEL,
            language=STT_LANGUAGES.get(language, language),
            **INFERENCE_AUTH,
        )

    provider, model = split_model(STT_MODEL)

    if provider == "sarvam" and sarvam is not None:
        # Sarvam is Indian and wants a full locale, not a bare code. Worth
        # trying against Deepgram on real worksite audio before going live.
        return sarvam.STT(model=model, language=f"{language}-IN")

    if provider == "deepgram" and deepgram is not None:
        return deepgram.STT(model=model, language=STT_LANGUAGES.get(language, language))

    if provider == "inworld" and inworld is not None:
        # Same account as the TTS, which is the point: one vendor, one key, and
        # a free tier that covers both. Wants BCP-47, like its TTS does.
        return inworld.STT(
            model=STT_MODEL,
            language=f"{language}-IN" if len(language) == 2 else language,
        )

    raise RuntimeError(f"No self-hosted STT plugin available for '{provider}'.")


def build_tts(meta: dict[str, Any]) -> tts_module.TTS:
    """
    The voice, told which language to speak.

    Same trap in reverse: the default voice reads Hindi words with English
    pronunciation, which is the fastest way to get hung up on. The voice id is
    provider-specific — Sarvam calls it a speaker, ElevenLabs a voice_id — so it
    is only passed when one is actually configured, and never across vendors: a
    name from the wrong provider synthesises silence rather than erroring.

    Language is passed provider-shaped too: Sarvam wants `hi-IN` in its own
    field, Cartesia a bare `hi`, Inworld a BCP-47 tag.
    """
    language = meta.get("language", "hi")
    voice = str(meta.get("voice") or "").strip()

    if SPEECH_BACKEND == "inference":
        return inference.TTS(
            model=TTS_MODEL,
            language=language,
            **({"voice": voice} if voice else {}),
            **INFERENCE_AUTH,
        )

    provider, model = split_model(TTS_MODEL)

    if provider == "sarvam" and sarvam is not None:
        return sarvam.TTS(
            model=model,
            target_language_code=f"{language}-IN",
            **({"speaker": voice} if voice else {}),
        )

    if provider == "cartesia" and cartesia is not None:
        return cartesia.TTS(model=model, language=language, **({"voice": voice} if voice else {}))

    if provider == "elevenlabs" and elevenlabs is not None:
        return elevenlabs.TTS(model=model, language=language, **({"voice_id": voice} if voice else {}))

    if provider == "inworld" and inworld is not None:
        # The only vendor whose free tier will clone a voice, so this is the
        # branch a cloned `SCREENING_VOICE` runs through. Cloned ids are
        # workspace-scoped and look like "silver-frog-2311__karigar-hindi";
        # built-in ones are bare names like "Meher".
        return inworld.TTS(
            model=model,
            language=f"{language}-IN" if len(language) == 2 else language,
            **({"voice": voice} if voice else {}),
        )

    raise RuntimeError(f"No self-hosted TTS plugin available for '{provider}'.")


def build_llm() -> llm_module.LLM:
    """
    The brain. On LiveKit Cloud this is the inference gateway; self-hosted it is
    any OpenAI-compatible endpoint — including the DigitalOcean Llama the rest
    of the platform's AI already runs on, which is why no new LLM account is
    needed to leave the cloud.
    """
    if SPEECH_BACKEND == "inference":
        return inference.LLM(model=LLM_MODEL, **INFERENCE_AUTH)

    if openai is None:
        raise RuntimeError("The openai plugin is required for a self-hosted LLM.")

    _, model = split_model(LLM_MODEL)

    return openai.LLM(
        model=model,
        **({"base_url": LLM_BASE_URL} if LLM_BASE_URL else {}),
        **({"api_key": LLM_API_KEY} if LLM_API_KEY else {}),
    )


async def entrypoint(ctx: JobContext) -> None:
    meta: dict[str, Any] = load_metadata(ctx)
    # No dial block means nobody is being rung: we are in console mode or an
    # inbound call, and whoever is in the room is who we talk to.
    dial = meta.get("dial")
    call_id = meta.get("room")

    await ctx.connect()

    if dial is None:
        logger.info("rehearsal mode — no phone line, talking to whoever is here")
        await converse(ctx, meta, call_id)
        return

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

    await converse(ctx, meta, call_id)


async def converse(ctx: JobContext, meta: dict[str, Any], call_id: str | None) -> None:
    """
    The conversation itself, once there is someone on the other end — a worker
    who answered the phone, or you in console mode. Identical either way, which
    is the whole point: a rehearsal exercises the real script.
    """
    session = AgentSession(
        stt=build_stt(meta),
        llm=build_llm(),
        tts=build_tts(meta),
    )

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

    try:
        await disconnected.wait()
    except asyncio.CancelledError:
        # Ctrl+C in console mode. Still report — the transcript up to here is
        # exactly what we wanted to look at.
        pass

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


async def report(call_id: str | None, **fields: Any) -> None:
    """
    Hand the result to Laravel. Fire and forget is not good enough — if this
    POST is lost the call row stays stuck in 'dialing' forever, so it retries.
    """
    payload = {"call_id": call_id, **{k: v for k, v in fields.items() if v is not None}}

    # A rehearsal with no call row behind it: print what would have been sent
    # rather than posting a result Laravel cannot match to anything.
    if not call_id:
        print(json.dumps(payload, indent=2, ensure_ascii=False))
        return

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
