<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UserResetPasswordCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly string $name,
        private readonly string $code,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Account Reset Password ')
            ->html("<h1>welcome {$this->name} to Yamak App</h1>  <br/><h3>  your reset password code is {$this->code} <br/> this code will expired in 2 days </h3>");
    }
}
