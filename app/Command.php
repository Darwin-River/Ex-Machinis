<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Command extends Model
{
    /** Size of the commands.code and commands.subject columns. */
    public const MAX_CODE_LENGTH = 2048;
    public const MAX_SUBJECT_LENGTH = 2048;

    protected $fillable = [
        'code', 'agent_id', 'subject', 'email_content'
    ];

    protected static function booted(): void
    {
        static::saving(function (Command $command) {
            if (mb_strlen((string) $command->code) > self::MAX_CODE_LENGTH) {
                throw new CommandTooLongException(mb_strlen($command->code), self::MAX_CODE_LENGTH);
            }
            // A long subject isn't worth rejecting a command for
            if ($command->subject !== null) {
                $command->subject = mb_substr($command->subject, 0, self::MAX_SUBJECT_LENGTH);
            }
        });
    }
}
