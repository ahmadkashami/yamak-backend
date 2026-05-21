<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UserRegistrationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly string $name,
        private readonly string $code,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('New User Registration')
            ->html("<h1>welcome {$this->name} to Yamak App  , your code is {$this->code}  </h1>");
    }
}
