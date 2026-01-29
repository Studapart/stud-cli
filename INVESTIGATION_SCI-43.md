# GitLab Compatibility Investigation Report - SCI-43

## Investigation Date
2024-12-19

## Current State Analysis

### Configuration
- ✅ `GIT_PROVIDER` config accepts both 'github' and 'gitlab' values (InitHandler.php line 70)
- ✅ Configuration is properly read via `_get_git_config()` function

### Code Analysis

#### 1. Provider Implementation
- ❌ Only `GithubProvider` class exists
- ❌ No `GitLabProvider` implementation
- ❌ No provider interface/abstraction exists

#### 2. URL Parsing
- ❌ `GitRepository::parseGithubUrl()` only handles GitHub URLs
  - Pattern: `#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?$#`
  - Will return empty array for GitLab URLs
- ❌ `getRepositoryOwner()` and `getRepositoryName()` will return `null` for GitLab repositories

#### 3. Provider Factory
- ❌ `_get_github_provider()` function only creates `GithubProvider` when `GIT_PROVIDER === 'github'`
- ❌ Returns `null` for any other provider value (including 'gitlab')

#### 4. Handler Usage
- ✅ Handlers gracefully handle `null` provider (check `if ($githubProvider)`)
- ✅ Git operations work (Git is provider-agnostic)
- ❌ Provider-specific operations (PR creation, comments, labels) are skipped when provider is null

### Expected Behavior with Current Code (GIT_PROVIDER=gitlab)

1. **URL Parsing**: Will fail - `getRepositoryOwner()` and `getRepositoryName()` return `null`
2. **Provider Creation**: Will be `null` - no GitLabProvider exists
3. **Commands Behavior**:
   - `stud submit`: Will fail when trying to create PR (provider is null)
   - `stud pr:comment`: Will fail with "No provider" error
   - `stud branches:list`: Will work for Git operations, but skip PR detection
   - `stud branches:clean`: Will work for Git operations, but skip PR detection
   - `stud branch:rename`: Will work for Git operations, but skip PR operations

## GitHub vs GitLab API Differences

### Authentication
- **GitHub**: `Authorization: Bearer {token}`, `Accept: application/vnd.github.v3+json`
- **GitLab**: `PRIVATE-TOKEN: {token}` or `Authorization: Bearer {token}`, API v4

### Repository Identification
- **GitHub**: Uses `owner/repo` format, separate owner and repo parameters
- **GitLab**: Uses project ID (integer) or project path (e.g., `owner/repo`), can use URL-encoded path

### Pull Requests vs Merge Requests
- **GitHub**: `/repos/{owner}/{repo}/pulls` (POST to create, GET to list)
- **GitLab**: `/projects/{id}/merge_requests` (POST to create, GET to list)
- **GitHub**: PR has `number`, `head`, `base`, `draft`, `state` (open/closed)
- **GitLab**: MR has `iid` (internal ID), `source_branch`, `target_branch`, `work_in_progress` (draft), `state` (opened/closed/merged)

### Labels
- **GitHub**: `/repos/{owner}/{repo}/issues/{number}/labels` (POST with array of label names)
- **GitLab**: `/projects/{id}/merge_requests/{iid}/labels` (POST with comma-separated string or array)

### Comments
- **GitHub**: `/repos/{owner}/{repo}/issues/{number}/comments` (POST)
- **GitLab**: `/projects/{id}/merge_requests/{iid}/notes` (POST)

### Finding MR by Branch
- **GitHub**: `/repos/{owner}/{repo}/pulls?head={owner}:{branch}&state={state}`
- **GitLab**: `/projects/{id}/merge_requests?source_branch={branch}&state={state}`

## Conclusion

**GitLab does NOT work with current code.** Implementation is required.

## Implementation Plan

1. Create `GitProviderInterface` with all methods used by handlers
2. Update `GithubProvider` to implement the interface
3. Create `GitLabProvider` implementing the interface
4. Refactor `GitRepository` to support both GitHub and GitLab URL parsing
5. Create provider factory function `_get_git_provider()` in castor.php
6. Update all handlers to use `GitProviderInterface` instead of `GithubProvider`
7. Update all castor.php tasks to use the factory function
8. Write comprehensive tests
9. Update documentation
