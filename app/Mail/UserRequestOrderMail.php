<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UserRequestOrderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(private readonly CarbonInterface $requestDate) {}

    public function build(): self
    {
        $date = $this->requestDate->toDateString();

        return $this
            ->subject('User Request Order')
            ->html("<h1> user had requesting you for help from yamak app {$date}  </h1>");
    }
}
