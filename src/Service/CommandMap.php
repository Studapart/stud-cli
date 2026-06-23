<?php

declare(strict_types=1);

namespace App\Service;

class CommandMap
{
    /**
     * Command names to translation keys and metadata (description, options, arguments).
     *
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    public static function all(): array
    {
        return array_merge(
            self::configCommands(),
            self::itemCommands(),
            self::branchCommands(),
            self::gitCommands(),
            self::confluenceCommands(),
            self::systemCommands(),
        );
    }

    /**
     * Short CLI alias => canonical command name. Used by `stud help <alias>` so routing matches Castor task aliases.
     *
     * @return array<string, string>
     */
    public static function aliasLookupMap(): array
    {
        $map = [];
        foreach (self::all() as $name => $meta) {
            $alias = $meta['alias'] ?? null;
            if (is_string($alias) && $alias !== '') {
                $map[$alias] = $name;
            }
        }

        return $map;
    }

    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    private static function configCommands(): array
    {
        return [
            'config:init' => [
                'alias' => 'init',
                'description_key' => 'help.command_config_init',
                'options' => [],
                'arguments' => [],
            ],
            'config:show' => [
                'alias' => null,
                'description_key' => 'help.command_config_show',
                'options' => [
                    ['name' => '--key', 'shortcut' => '-k', 'description_key' => 'help.option_config_show_key', 'argument' => '<key>'],
                    ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_config_show_quiet', 'argument' => null],
                ],
                'arguments' => [],
            ],
            'config:validate' => [
                'alias' => null,
                'description_key' => 'help.command_config_validate',
                'options' => [
                    ['name' => '--skip-jira', 'shortcut' => null, 'description_key' => 'help.option_config_validate_skip_jira', 'argument' => null],
                    ['name' => '--skip-git', 'shortcut' => null, 'description_key' => 'help.option_config_validate_skip_git', 'argument' => null],
                    ['name' => '--skip-linear', 'shortcut' => null, 'description_key' => 'help.option_config_validate_skip_linear', 'argument' => null],
                ],
                'arguments' => [],
            ],
            'config:project-init' => [
                'alias' => 'cpi',
                'description_key' => 'help.command_config_project_init',
                'options' => [],
                'arguments' => [],
            ],
        ];
    }

    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    private static function itemCommands(): array
    {
        return array_merge(
            self::itemListCommands(),
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

    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    private static function branchCommands(): array
    {
        return [
            'branch:rename' => [
                'alias' => 'rn',
                'description_key' => 'help.command_branch_rename',
                'options' => [
                    ['name' => '--name', 'shortcut' => '-N', 'description_key' => 'help.option_branch_rename_name', 'argument' => '<name>'],
                    ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_quiet', 'argument' => null],
                ],
                'arguments' => ['[<branch>]', '[<key>]'],
            ],
            'branches:list' => [
                'alias' => 'bl',
                'description_key' => 'help.command_branches_list',
                'options' => [],
                'arguments' => [],
            ],
            'branches:clean' => [
                'alias' => 'bc',
                'description_key' => 'help.command_branches_clean',
                'options' => [
                    ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_branches_clean_quiet', 'argument' => null],
                ],
                'arguments' => [],
            ],
        ];
    }

    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    private static function gitCommands(): array
    {
        return WorkflowCommandMap::commands();
    }

    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    private static function confluenceCommands(): array
    {
        return [
            'confluence:push' => [
                'alias' => 'cpu',
                'description_key' => 'help.command_confluence_push',
                'options' => [
                    ['name' => '--space', 'shortcut' => '-s', 'description_key' => 'help.option_confluence_push_space', 'argument' => '<key>'],
                    ['name' => '--title', 'shortcut' => '-t', 'description_key' => 'help.option_confluence_push_title', 'argument' => '<title>'],
                    ['name' => '--file', 'shortcut' => '-f', 'description_key' => 'help.option_confluence_push_file', 'argument' => '<path>'],
                    ['name' => '--page', 'shortcut' => '-p', 'description_key' => 'help.option_confluence_push_page', 'argument' => '<id>'],
                    ['name' => '--parent', 'shortcut' => null, 'description_key' => 'help.option_confluence_push_parent', 'argument' => '<id>'],
                    ['name' => '--url', 'shortcut' => null, 'description_key' => 'help.option_confluence_push_url', 'argument' => null],
                    ['name' => '--status', 'shortcut' => null, 'description_key' => 'help.option_confluence_push_status', 'argument' => null],
                    ['name' => '--contact-email', 'shortcut' => null, 'description_key' => 'help.option_confluence_push_contact_email', 'argument' => '<email>'],
                ],
                'arguments' => ['[inputFile]'],
            ],
            'confluence:show' => [
                'alias' => 'csh',
                'description_key' => 'help.command_confluence_show',
                'options' => [
                    ['name' => '--page', 'shortcut' => '-p', 'description_key' => 'help.option_confluence_show_page', 'argument' => '<id>'],
                    ['name' => '--url', 'shortcut' => null, 'description_key' => 'help.option_confluence_show_url', 'argument' => '<url>'],
                    ['name' => '--confluence-url', 'shortcut' => null, 'description_key' => 'help.option_confluence_push_url', 'argument' => null],
                ],
                'arguments' => ['[inputFile]'],
            ],
            'confluence:page-labels' => [
                'alias' => null,
                'description_key' => 'help.command_confluence_page_labels',
                'options' => [
                    ['name' => '--page', 'shortcut' => '-p', 'description_key' => 'help.option_confluence_page_labels_page', 'argument' => '<id>'],
                    ['name' => '--labels', 'shortcut' => '-l', 'description_key' => 'help.option_confluence_page_labels_labels', 'argument' => '<list>'],
                    ['name' => '--url', 'shortcut' => null, 'description_key' => 'help.option_confluence_push_url', 'argument' => null],
                ],
                'arguments' => [],
            ],
        ];
    }

    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    private static function systemCommands(): array
    {
        return array_merge(
            self::toolCommands(),
            self::filterCommands(),
        );
    }

    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    private static function filterCommands(): array
    {
        return [
            'filters:list' => [
                'alias' => 'fl',
                'description_key' => 'help.command_filters_list',
                'options' => [],
                'arguments' => [],
            ],
            'filters:show' => [
                'alias' => 'fs',
                'description_key' => 'help.command_filters_show',
                'options' => [],
                'arguments' => ['<filterName>'],
            ],
        ];
    }

    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    private static function toolCommands(): array
    {
        return array_merge(
            [
                'completion' => [
                    'alias' => null,
                    'description_key' => 'help.command_completion',
                    'options' => [],
                    'arguments' => ['<shell>'],
                ],
                'docs:generate' => [
                    'alias' => 'dg',
                    'description_key' => 'help.command_docs_generate',
                    'options' => [],
                    'arguments' => [],
                ],
                'docs:check' => [
                    'alias' => 'dc',
                    'description_key' => 'help.command_docs_check',
                    'options' => [],
                    'arguments' => [],
                ],
                'help' => [
                    'alias' => null,
                    'description_key' => 'help.command_help',
                    'options' => [],
                    'arguments' => ['[<command>]'],
                ],
                'projects:list' => [
                    'alias' => 'pj',
                    'description_key' => 'help.command_projects_list',
                    'options' => [],
                    'arguments' => [],
                ],
                'projects:workflow' => [
                    'alias' => null,
                    'description_key' => 'help.command_projects_workflow',
                    'options' => [
                        ['name' => '--project', 'shortcut' => null, 'description_key' => 'help.option_projects_workflow_project', 'argument' => 'KEY'],
                    ],
                    'arguments' => [],
                ],
                'update' => [
                    'alias' => 'up',
                    'description_key' => 'help.command_update',
                    'options' => [
                        ['name' => '--info', 'shortcut' => '-i', 'description_key' => 'help.option_update_info', 'argument' => null],
                        ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_quiet', 'argument' => null],
                    ],
                    'arguments' => [],
                ],
            ],
            self::releaseAndDeployCommands(),
        );
    }

    /**
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    private static function releaseAndDeployCommands(): array
    {
        return [
            'release' => [
                'alias' => 'rl',
                'description_key' => 'help.command_release',
                'options' => [
                    ['name' => '--major', 'shortcut' => '-M', 'description_key' => 'help.option_release_major', 'argument' => null],
                    ['name' => '--minor', 'shortcut' => '-m', 'description_key' => 'help.option_release_minor', 'argument' => null],
                    ['name' => '--patch', 'shortcut' => '-b', 'description_key' => 'help.option_release_patch', 'argument' => null],
                    ['name' => '--publish', 'shortcut' => '-p', 'description_key' => 'help.option_release_publish', 'argument' => null],
                    ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_quiet', 'argument' => null],
                ],
                'arguments' => ['[<version>]'],
            ],
            'deploy' => [
                'alias' => 'mep',
                'description_key' => 'help.command_deploy',
                'options' => [
                    ['name' => '--clean', 'shortcut' => null, 'description_key' => 'help.option_deploy_clean', 'argument' => null],
                ],
                'arguments' => [],
            ],
        ];
    }
}
