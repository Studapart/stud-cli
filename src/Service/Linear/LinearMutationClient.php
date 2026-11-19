<?php

declare(strict_types=1);

namespace App\Service\Linear;

use App\Exception\ApiException;
use App\Service\LinearGraphqlClient;

/**
 * Linear GraphQL writes: issue create/update, file upload targets, and attachments.
 */
class LinearMutationClient
{
    private const ISSUE_CREATE_MUTATION = <<<'GQL'
        mutation IssueCreate($input: IssueCreateInput!) {
          issueCreate(input: $input) {
            success
            issue {
              id
              identifier
              url
            }
          }
        }
        GQL;

    private const ISSUE_UPDATE_MUTATION = <<<'GQL'
        mutation IssueUpdate($id: String!, $input: IssueUpdateInput!) {
          issueUpdate(id: $id, input: $input) {
            success
            issue {
              id
              identifier
            }
          }
        }
        GQL;

    private const FILE_UPLOAD_MUTATION = <<<'GQL'
        mutation FileUpload($filename: String!, $contentType: String!, $size: Int!) {
          fileUpload(filename: $filename, contentType: $contentType, size: $size) {
            success
            uploadFile {
              uploadUrl
              assetUrl
              headers {
                key
                value
              }
            }
          }
        }
        GQL;

    private const ATTACHMENT_CREATE_MUTATION = <<<'GQL'
        mutation AttachmentCreate($input: AttachmentCreateInput!) {
          attachmentCreate(input: $input) {
            success
            attachment {
              id
              url
            }
          }
        }
        GQL;

    /**
     * issueUpdate input keys in payload order; `true` sends explicit nulls, `false` skips them.
     */
    private const UPDATE_FIELDS = [
        LinearIssueMutationKeys::TITLE => false,
        LinearIssueMutationKeys::DESCRIPTION => true,
        LinearIssueMutationKeys::LABEL_IDS => false,
        LinearIssueMutationKeys::PRIORITY => true,
        LinearIssueMutationKeys::STATE_ID => false,
        LinearIssueMutationKeys::ASSIGNEE_ID => false,
    ];

    public function __construct(
        private readonly LinearGraphqlClient $graphqlClient,
    ) {
    }

    /**
     * @param array{teamId: string, title: string, description: ?string, labelIds: list<string>, priority: ?int, parentId: ?string} $input
     *
     * @return array{identifier: string, url: string}
     */
    public function issueCreate(array $input): array
    {
        $result = $this->mutate(
            self::ISSUE_CREATE_MUTATION,
            ['input' => $this->buildCreatePayload($input)],
            'issueCreate',
            'Could not create Linear issue.',
        );

        $issue = $result['issue'] ?? null;
        if (! is_array($issue) || ! isset($issue['identifier'], $issue['url'])) {
            throw new ApiException(
                'Could not create Linear issue.',
                'issueCreate response missing issue identifier or url.',
            );
        }

        return [
            'identifier' => (string) $issue['identifier'],
            'url' => (string) $issue['url'],
        ];
    }

    /**
     * @param array{title?: string, description?: ?string, labelIds?: list<string>, priority?: ?int, stateId?: string, assigneeId?: string} $input
     */
    public function issueUpdate(string $issueId, array $input): void
    {
        if ($input === []) {
            return;
        }

        $payload = [];
        foreach (self::UPDATE_FIELDS as $key => $sendNull) {
            if ($sendNull ? array_key_exists($key, $input) : isset($input[$key])) {
                $payload[$key] = $input[$key];
            }
        }

        $this->mutate(
            self::ISSUE_UPDATE_MUTATION,
            ['id' => $issueId, 'input' => $payload],
            'issueUpdate',
            'Could not update Linear issue.',
        );
    }

    /**
     * @return array{uploadUrl: string, assetUrl: string, headers: list<array{key: string, value: string}>}
     */
    public function fileUpload(string $filename, string $contentType, int $size): array
    {
        $result = $this->mutate(self::FILE_UPLOAD_MUTATION, [
            LinearAttachmentMutationKeys::FILENAME => $filename,
            LinearAttachmentMutationKeys::CONTENT_TYPE => $contentType,
            LinearAttachmentMutationKeys::SIZE => $size,
        ], 'fileUpload', 'Linear file upload request failed.');

        $uploadFile = $result['uploadFile'] ?? null;
        if (! is_array($uploadFile)) {
            throw new ApiException(
                'Linear file upload request failed.',
                'fileUpload response missing uploadFile.',
            );
        }

        $uploadUrl = $uploadFile['uploadUrl'] ?? null;
        $assetUrl = $uploadFile['assetUrl'] ?? null;
        if (! is_string($uploadUrl) || $uploadUrl === '' || ! is_string($assetUrl) || $assetUrl === '') {
            throw new ApiException(
                'Linear file upload request failed.',
                'fileUpload response missing uploadUrl or assetUrl.',
            );
        }

        return [
            'uploadUrl' => $uploadUrl,
            'assetUrl' => $assetUrl,
            'headers' => $this->parseFileUploadHeaders($uploadFile['headers'] ?? null),
        ];
    }

    public function attachmentCreate(string $issueId, string $title, string $url): void
    {
        $this->mutate(self::ATTACHMENT_CREATE_MUTATION, [
            'input' => [
                LinearAttachmentMutationKeys::ISSUE_ID => $issueId,
                LinearIssueMutationKeys::TITLE => $title,
                LinearAttachmentMutationKeys::URL => $url,
            ],
        ], 'attachmentCreate', 'Could not attach file to Linear issue.');
    }

    /**
     * @param array{teamId: string, title: string, description: ?string, labelIds: list<string>, priority: ?int, parentId: ?string} $input
     *
     * @return array<string, mixed>
     */
    private function buildCreatePayload(array $input): array
    {
        $payload = [
            LinearIssueMutationKeys::TEAM_ID => $input[LinearIssueMutationKeys::TEAM_ID],
            LinearIssueMutationKeys::TITLE => $input[LinearIssueMutationKeys::TITLE],
        ];

        if ($input[LinearIssueMutationKeys::DESCRIPTION] !== null && $input[LinearIssueMutationKeys::DESCRIPTION] !== '') {
            $payload[LinearIssueMutationKeys::DESCRIPTION] = $input[LinearIssueMutationKeys::DESCRIPTION];
        }
        if ($input[LinearIssueMutationKeys::LABEL_IDS] !== []) {
            $payload[LinearIssueMutationKeys::LABEL_IDS] = $input[LinearIssueMutationKeys::LABEL_IDS];
        }
        if ($input[LinearIssueMutationKeys::PRIORITY] !== null) {
            $payload[LinearIssueMutationKeys::PRIORITY] = $input[LinearIssueMutationKeys::PRIORITY];
        }
        if ($input[LinearIssueMutationKeys::PARENT_ID] !== null && $input[LinearIssueMutationKeys::PARENT_ID] !== '') {
            $payload[LinearIssueMutationKeys::PARENT_ID] = $input[LinearIssueMutationKeys::PARENT_ID];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return array<mixed>
     */
    private function mutate(string $mutation, array $variables, string $field, string $errorMessage): array
    {
        $data = $this->graphqlClient->query($mutation, $variables);
        $result = $data[$field] ?? null;
        if (! is_array($result) || ! ($result['success'] ?? false)) {
            throw new ApiException(
                $errorMessage,
                json_encode($result, JSON_UNESCAPED_UNICODE) ?: $field . ' returned success=false',
            );
        }

        return $result;
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private function parseFileUploadHeaders(mixed $rawHeaders): array
    {
        if (! is_array($rawHeaders)) {
            return [];
        }

        $headers = [];
        foreach ($rawHeaders as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = $row['key'] ?? null;
            $value = $row['value'] ?? null;
            if (! is_string($key) || $key === '' || ! is_string($value)) {
                continue;
            }

            $headers[] = ['key' => $key, 'value' => $value];
        }

        return $headers;
    }
}
