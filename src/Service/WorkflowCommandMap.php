<?php

declare(strict_types=1);

namespace App\Service;

class WorkflowCommandMap
{
    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    public static function commands(): array
    {
        return array_merge(
            self::commitCommands(),
            self::workflowCommands(),
        );
    }

    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    private static function commitCommands(): array
    {
        return [
            'commit' => [
                'alias' => 'co',
                'description_key' => 'help.command_commit',
                'options' => [
                    ['name' => '--new', 'shortcut' => null, 'description_key' => 'help.option_commit_new', 'argument' => null],
                    ['name' => '--message', 'shortcut' => '-m', 'description_key' => 'help.option_commit_message', 'argument' => '<message>'],
                    ['name' => '--all', 'shortcut' => '-a', 'description_key' => 'help.option_commit_all', 'argument' => null],
                    ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_quiet', 'argument' => null],
                ],
                'arguments' => [],
            ],
            'commit:undo' => [
                'alias' => 'undo',
                'description_key' => 'help.command_commit_undo',
                'options' => [
                    ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_quiet', 'argument' => null],
                ],
                'arguments' => [],
            ],
            'push' => [
                'alias' => 'ps',
                'description_key' => 'help.command_push',
                'options' => [
                    ['name' => '--new', 'shortcut' => null, 'description_key' => 'help.option_commit_new', 'argument' => null],
                    ['name' => '--message', 'shortcut' => '-m', 'description_key' => 'help.option_commit_message', 'argument' => '<message>'],
                    ['name' => '--all', 'shortcut' => '-a', 'description_key' => 'help.option_commit_all', 'argument' => null],
                    ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_quiet', 'argument' => null],
                    ['name' => '--no-please', 'shortcut' => null, 'description_key' => 'help.option_push_no_please', 'argument' => null],
                ],
                'arguments' => [],
            ],
        ];
    }

    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    private static function workflowCommands(): array
    {
        return array_merge(
            self::workflowSimpleCommands(),
            self::workflowSubmitCommands(),
        );
    }

    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    private static function workflowSimpleCommands(): array
    {
        return [
            'please' => [
                'alias' => 'pl',
                'description_key' => 'help.command_please',
                'options' => [],
                'arguments' => [],
            ],
            'flatten' => [
                'alias' => 'ft',
                'description_key' => 'help.command_flatten',
                'options' => [],
                'arguments' => [],
            ],
            'sync' => [
                'alias' => 'sy',
                'description_key' => 'sync.help_description',
                'options' => [],
                'arguments' => [],
            ],
            'switch' => [
                'alias' => 'sw',
                'description_key' => 'branch.switch.help_description',
                'options' => [
                    ['name' => '--sync', 'shortcut' => '-s', 'description_key' => 'branch.switch.option_sync', 'argument' => null],
                    ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_quiet', 'argument' => null],
                ],
                'arguments' => ['<key>'],
            ],
            'cache:clear' => [
                'alias' => 'cc',
                'description_key' => 'help.command_cache_clear',
                'options' => [],
                'arguments' => [],
            ],
            'status' => [
                'alias' => 'ss',
                'description_key' => 'help.command_status',
                'options' => [],
                'arguments' => [],
            ],
        ];
    }

    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    private static function workflowSubmitCommands(): array
    {
        return [
            'submit' => [
                'alias' => 'su',
                'description_key' => 'help.command_submit',
                'options' => [
                    ['name' => '--draft', 'shortcut' => '-d', 'description_key' => 'help.option_submit_draft', 'argument' => null],
                    ['name' => '--labels', 'shortcut' => null, 'description_key' => 'help.option_submit_labels', 'argument' => '<labels>'],
                    ['name' => '--assign-to-author', 'shortcut' => null, 'description_key' => 'help.option_submit_assign_to_author', 'argument' => null],
                    ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_quiet', 'argument' => null],
                ],
                'arguments' => [],
            ],
            'pr:comment' => [
                'alias' => 'pc',
                'description_key' => 'help.command_pr_comment',
                'options' => [
                    ['name' => '--reply-to', 'shortcut' => null, 'description_key' => 'help.option_pr_comment_reply_to', 'argument' => '<target>'],
                    ['name' => '--resolve', 'shortcut' => null, 'description_key' => 'help.option_pr_comment_resolve', 'argument' => null],
                ],
                'arguments' => ['<message>'],
            ],
            'pr:comments' => [
                'alias' => 'pcs',
                'description_key' => 'help.command_pr_comments',
                'options' => [
                    ['name' => '--threaded', 'shortcut' => null, 'description_key' => 'help.option_pr_comments_threaded', 'argument' => null],
                ],
                'arguments' => [],
            ],
        ];
    }
}
