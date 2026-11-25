<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Command extends Model
{
    /**
     * The longest command, in bytes: what the engine reads of one
     * (MAX_COMMAND_CODE_SIZE in game-engine/src/include/common_types.h;
     * commands.code is TEXT, 65,535 bytes). A released script goes to its
     * drone in pieces of at most this, split at line ends, so it is also the
     * longest line a script may have (#1035: it was 2,048, the column's size
     * in the FORTH days).
     */
    public const MAX_CODE_LENGTH = 16000;
    /** Size of the commands.subject column. */
    public const MAX_SUBJECT_LENGTH = 2048;

    protected $fillable = [
        'code', 'agent_id', 'subject', 'email_content'
    ];

    protected static function booted(): void
    {
        static::saving(function (Command $command) {
            if (strlen((string) $command->code) > self::MAX_CODE_LENGTH) {
                throw new CommandTooLongException(strlen($command->code), self::MAX_CODE_LENGTH);
            }
            // A long subject isn't worth rejecting a command for
            if ($command->subject !== null) {
                $command->subject = mb_substr($command->subject, 0, self::MAX_SUBJECT_LENGTH);
            }
        });
    }
}
