<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TemplatedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, string>  $files  filename => base64 contents. Base64
     *                                        because the mail is queued, and raw PDF
     *                                        bytes do not survive the queue's JSON.
     */
    public function __construct(
        public string $subjectLine,
        public string $bodyHtml,
        public array $files = [],
    ) {}

    public function build(): self
    {
        $mail = $this->subject($this->subjectLine)
            ->view('emails.templated')
            ->with(['bodyHtml' => $this->bodyHtml]);

        foreach ($this->files as $name => $contents) {
            $mail->attachData(base64_decode($contents), $name, [
                'mime' => str_ends_with(strtolower($name), '.pdf') ? 'application/pdf' : 'application/octet-stream',
            ]);
        }

        return $mail;
    }
}
