<?php

declare(strict_types=1);

namespace App\Service;

class ItemCommandMap
{
    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    public static function commands(): array
    {
        return array_merge(
            self::itemListCommands(),
            self::itemAttachmentCommands(),
            self::itemActionCommands(),
        );
    }

    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    private static function itemListCommands(): array
    {
        return [
            'items:list' => [
                'alias' => 'ls',
                'description_key' => 'help.command_items_list',
                'options' => [
                    ['name' => '--all', 'shortcut' => '-a', 'description_key' => 'help.option_all', 'argument' => null],
                    ['name' => '--project', 'shortcut' => '-p', 'description_key' => 'help.option_project', 'argument' => '<key>'],
                    ['name' => '--sort', 'shortcut' => '-s', 'description_key' => 'help.option_items_list_sort', 'argument' => '<value>'],
                ],
                'arguments' => [],
            ],
            'items:search' => [
                'alias' => 'search',
                'description_key' => 'help.command_items_search',
                'options' => [],
                'arguments' => ['<jql>'],
            ],
            'items:show' => [
                'alias' => 'sh',
                'description_key' => 'help.command_items_show',
                'options' => [],
                'arguments' => ['<key>'],
            ],
        ];
    }

    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    private static function itemAttachmentCommands(): array
    {
        return [
            'items:download' => [
                'alias' => 'idl',
                'description_key' => 'help.command_items_download',
                'options' => [
                    ['name' => '--url', 'shortcut' => null, 'description_key' => 'help.option_items_download_url', 'argument' => '<url>'],
                    ['name' => '--path', 'shortcut' => null, 'description_key' => 'help.option_items_download_path', 'argument' => '<dir>'],
                ],
                'arguments' => ['[<key>]'],
            ],
            'items:upload' => [
                'alias' => 'iup',
                'description_key' => 'help.command_items_upload',
                'options' => [
                    ['name' => '--file', 'shortcut' => '-f', 'description_key' => 'help.option_items_upload_file', 'argument' => '<path>'],
                ],
                'arguments' => ['<key>'],
            ],
        ];
    }

    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    private static function itemCreateCommand(): array
    {
        return [
            'items:create' => [
                'alias' => 'ic',
                'description_key' => 'help.command_items_create',
                'options' => [
                    ['name' => '--project', 'shortcut' => '-p', 'description_key' => 'help.option_items_create_project', 'argument' => '<key>'],
                    ['name' => '--type', 'shortcut' => '-t', 'description_key' => 'help.option_items_create_type', 'argument' => '<type>'],
                    ['name' => '--summary', 'shortcut' => '-m', 'description_key' => 'help.option_items_create_summary', 'argument' => '<text>'],
                    ['name' => '--description', 'shortcut' => '-d', 'description_key' => 'help.option_items_create_description', 'argument' => '<text>'],
                    ['name' => '--description-format', 'shortcut' => null, 'description_key' => 'help.option_items_create_description_format', 'argument' => '<plain|markdown>'],
                    ['name' => '--parent', 'shortcut' => null, 'description_key' => 'help.option_items_create_parent', 'argument' => '<key>'],
                    ['name' => '--fields', 'shortcut' => '-F', 'description_key' => 'help.option_fields', 'argument' => '<fields>'],
                ],
                'arguments' => [],
            ],
        ];
    }

    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    private static function itemUpdateCommand(): array
    {
        return [
            'items:update' => [
                'alias' => 'iu',
                'description_key' => 'help.command_items_update',
                'options' => [
                    ['name' => '--summary', 'shortcut' => '-m', 'description_key' => 'help.option_items_update_summary', 'argument' => '<text>'],
                    ['name' => '--description', 'shortcut' => '-d', 'description_key' => 'help.option_items_update_description', 'argument' => '<text>'],
                    ['name' => '--description-format', 'shortcut' => null, 'description_key' => 'help.option_items_update_description_format', 'argument' => '<plain|markdown>'],
                    ['name' => '--fields', 'shortcut' => '-F', 'description_key' => 'help.option_fields', 'argument' => '<fields>'],
                ],
                'arguments' => ['<key>'],
            ],
        ];
    }

    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    private static function itemActionCommands(): array
    {
        return array_merge(
            self::itemCreateCommand(),
            self::itemUpdateCommand(),
            [
                'items:transition' => [
                    'alias' => 'tx',
                    'description_key' => 'help.command_items_transition',
                    'options' => [],
                    'arguments' => ['[<key>]'],
                ],
                'items:start' => [
                    'alias' => 'start',
                    'description_key' => 'help.command_items_start',
                    'options' => [],
                    'arguments' => ['<key>'],
                ],
                'items:takeover' => [
                    'alias' => 'to',
                    'description_key' => 'help.command_items_takeover',
                    'options' => [
                        ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_quiet', 'argument' => null],
                    ],
                    'arguments' => ['<key>'],
                ],
            ],
        );
    }
}
