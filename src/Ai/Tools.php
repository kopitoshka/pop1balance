<?php

declare(strict_types=1);

namespace P1Bot\Ai;

/** JSON schemas of the functions the model may call. Stub: names only for now. */
final class Tools
{
    public const PLAYER_TOOLS = [
        'list_tournaments', 'tournament_info', 'register_team', 'add_player', 'remove_player',
        'replace_player', 'rename_team', 'transfer_captain', 'withdraw_team', 'leave_team',
        'join_solo', 'leave_solo', 'my_status', 'ask_clarification',
    ];

    public const ADMIN_TOOLS = [
        'create_tournament', 'edit_tournament', 'cancel_tournament', 'open_registration',
        'close_registration', 'set_max_players', 'set_level', 'create_balance', 'show_balance',
    ];

    /** @return list<array<string, mixed>> */
    public static function definitions(bool $isAdmin): array
    {
        throw new \LogicException('Not implemented yet');
    }
}
