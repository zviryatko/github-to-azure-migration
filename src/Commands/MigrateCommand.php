<?php

namespace zviryatko\Github2Azure\Commands;

use FrankHouweling\AzureDevOpsClient\Git\Api\CommitsApi;
use FrankHouweling\AzureDevOpsClient\Git\Api\PullRequestsApi;
use FrankHouweling\AzureDevOpsClient\Git\Api\PullRequestThreadsApi;
use FrankHouweling\AzureDevOpsClient\Git\Model\GitPullRequest;
use FrankHouweling\AzureDevOpsClient\Wit\Api\WorkItemsApi;
use FrankHouweling\AzureDevOpsClient\Wit\Model\WorkItem;
use GuzzleHttp\Client as GuzzleClient;
use Github\Client as GithubClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Query;
use League\CommonMark\CommonMarkConverter;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function GuzzleHttp\Psr7\parse_query;

/**
 * Export Github issues into csv file.
 */
final class MigrateCommand extends Command {

  protected static $defaultName = 'migrate';

  private GithubClient $source;

  /**
   * Key value list of Github users and their Azure DevOps aliases.
   *
   * @var array
   */
  private array $userMap = [];

  /**
   * Markdown converter.
   *
   * @var \League\CommonMark\CommonMarkConverter
   */
  private CommonMarkConverter $converter;

  /**
   * Azure DevOps organization.
   */
  private string $targetOrg;

  /**
   * Azure DevOps project.
   */
  private string $targetRepo;

  /**
   * Work Items Api.
   */
  private WorkItemsApi $workItemsApi;

  /**
   * Link Github issue to Azure DevOps work item.
   *
   * @var bool
   */
  private bool $linkGithubIssue;

  /**
   * Github organization.
   *
   * @var string
   */
  private string $sourceOrg;

  /**
   * Github repository.
   *
   * @var string
   */
  private string $sourceRepo;

  /**
   * Azure DevOps commits api.
   *
   * @var \FrankHouweling\AzureDevOpsClient\Git\Api\CommitsApi
   */
  private CommitsApi $commitsApi;

  /**
   * Azure DevOps pull requests api.
   *
   * @var \FrankHouweling\AzureDevOpsClient\Git\Api\PullRequestsApi
   */
  private PullRequestsApi $pullRequestApi;

  /**
   * Azure DevOps pull requests thread api.
   *
   * @var \FrankHouweling\AzureDevOpsClient\Git\Api\PullRequestThreadsApi
   */
  private PullRequestThreadsApi $pullRequestThreadsApi;

  /**
   * {@inheritDoc}
   */
  protected function configure() {
    $this->addOption('usermap', 'u', InputArgument::OPTIONAL, 'Path to CSV file with user reference between Github user names and Azure DevOps users.');
    $this->addOption('link-github-issue', 'link', InputArgument::OPTIONAL, 'Add link to Github issue, Repository must be connected to Azure DevOps.');
  }

  /**
   * {@inheritDoc}
   */
  public function initialize(InputInterface $input, OutputInterface $output) {
    $this->linkGithubIssue = (bool) $input->getOption('link-github-issue');
    $host = getenv('GITHUB_HOST');
    $token = getenv('GITHUB_TOKEN');
    $enterpriseUrl = parse_url($host, PHP_URL_HOST) !== 'api.github.com' ? $host : NULL;
    $this->source = new GithubClient(NULL, NULL, $enterpriseUrl);
    $this->source->authenticate($token, NULL, GithubClient::AUTH_ACCESS_TOKEN);
    $this->sourceOrg = getenv('GITHUB_ORG');
    $this->sourceRepo = getenv('GITHUB_REPO');

    $usermap_file = $input->getOption('usermap');
    if (!empty($usermap_file) && file_exists($usermap_file)) {
      $csv = array_map('str_getcsv', file($usermap_file));
      $map = array_combine(array_column($csv, 0), array_column($csv, 1));
      $this->userMap = array_map('trim', $map);
    }
    $this->converter = new CommonMarkConverter();

    $this->targetOrg = getenv('AZURE_ORG');
    $this->targetRepo = getenv('AZURE_REPO');

    $handler = HandlerStack::create();
    $handler->push(Middleware::mapRequest(function(RequestInterface  $request) {
      $query = Query::parse($request->getUri()->getQuery());
      $query['bypassRules'] = 'True';
      return $request->withUri($request->getUri()->withQuery(Query::build($query)));
    }));
    $guzzle = new GuzzleClient([
      'headers' => ['Content-Type' => 'application/json-patch+json'],
      'handler' => $handler,
    ]);
    $headerSelection = new class extends \FrankHouweling\AzureDevOpsClient\Wit\HeaderSelector {

      public function selectHeaders($accept, $contentTypes) {
        $headers = parent::selectHeaders($accept, $contentTypes);
        $headers['Content-Type'] = reset($contentTypes);
        return $headers;
      }
    };
    $this->workItemsApi = new WorkItemsApi(
      $guzzle,
      \FrankHouweling\AzureDevOpsClient\Wit\Configuration::getDefaultConfiguration()
        ->setUsername(getenv('AZURE_USER'))
        ->setPassword(getenv('AZURE_TOKEN')),
      $headerSelection,
    );
    $gitConfig = \FrankHouweling\AzureDevOpsClient\Git\Configuration::getDefaultConfiguration()
      ->setUsername(getenv('AZURE_USER'))
      ->setPassword(getenv('AZURE_TOKEN'));
    $this->commitsApi = new CommitsApi(
      $guzzle,
      $gitConfig,
    );
    $this->pullRequestApi = new PullRequestsApi(
      $guzzle,
      $gitConfig,
    );
    $this->pullRequestThreadsApi = new PullRequestThreadsApi(
      $guzzle,
      $gitConfig,
    );
  }

  /**
   * Prepare fields for Work Items api call.
   *
   * @param array $fields
   *
   * @return array
   *   List of operations.
   */
  private function prepareFields(array $fields) {
    $ops = [];
    foreach (array_filter($fields) as $field => $value) {
      $ops[] = [
        'op' => 'add',
        'path' => "/fields/$field",
        'value' => $value,
      ];
    }
    return $ops;
  }

  /**
   * Get mapped Azure DevOps user for Github login.
   *
   * @param string $user
   *
   * @return string|array|null
   */
  private function getLocalUser(string $user) {
    if (!empty($this->userMap[$user])) {
      return $this->userMap[$user];
    }

    $data = [
      'displayName' => $user,
      'uniqueName' => $user,
    ];
    $account = $this->source->user()->show($user);
    if (!empty($account)) {
      $data = [
        'displayName' => $account['name'] ?? $user,
        'uniqueName' => $account['login'] ?? $user,
        'imageUrl' => $account['avatar_url'],
        'url' => $account['url'],
      ];
    }
    $this->userMap[$user] = $data;
    return $this->userMap[$user];
  }

  /**
   * Find and replace GH issue IDs with Azure Work Item IDs.
   *
   * @param string $content
   * @param array $issues
   * @param string $prefix
   * @param bool $link
   *
   * @return string|string[]
   */
  private function replaceIssueIds(string $content, array $issues, string $prefix, bool $link) {
    foreach ($issues as $old_id => [$new_id, $new_url]) {
      $replace = $link ? "<a href=\"$new_url\">#$new_id</a>" : "#$new_id";
      $content = preg_replace("/{$prefix}{$old_id}\b/", $replace, $content);
    }
    return $content;
  }

  /**
   * {@inheritDoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $milestones = $this->migrateMilestones($output);
    $issues = $this->migrateIssues($output, $milestones ?? []);
    $this->migratePullRequests($output, $issues ?? []);
    return Command::SUCCESS;
  }

  /**
   * Migrate milestones into Epics.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *
   * @return array
   *   List with Github milestone ids and Azure DevOps work item url.
   */
  private function migrateMilestones(OutputInterface $output): array {
    $per_page = 10;
    $page = 1;
    $results = [];
    do {
      $milestones = $this->source->issue()->milestones()->all($this->sourceOrg, $this->sourceRepo, [
        'per_page' => $per_page,
        'page' => $page,
      ]);
      foreach ($milestones as $milestone) {
        try {
          $workItem = $this->createWorkItemFromMilestone($milestone);
          $output->writeln(sprintf('<info>Migrated milestone "%d" to epic "%d".</info>', $milestone['number'], $workItem->getId()));
          $results[$milestone['number']] = $workItem->getUrl();
        } catch (\Exception $e) {
          if (!isset($workItem)) {
            $output->writeln(sprintf('<error>Failed to migrate milestone "%d" with next error: "%s".</error>', $milestone['number'], $e->getMessage()));
          }
          else {
            $output->writeln(sprintf('<error>Failed to add comment to "%d" with next error: "%s".</error>', $workItem->getId(), $e->getMessage()));
          }
        }
      }
      $page++;
    } while (!empty($milestones));
    return $results;
  }

  /**
   * Create Epic based on Milestone.
   *
   * @param array $milestone
   *
   * @return \FrankHouweling\AzureDevOpsClient\Wit\Model\WorkItem
   */
  private function createWorkItemFromMilestone(array $milestone): WorkItem {
    $body = $this->prepareFields([
      'System.Title' => $milestone['title'],
      'System.CreatedDate' => $milestone['created_at'],
      'System.CreatedBy' => !empty($this->userMap[$milestone['creator']['login']]) ? $this->userMap[$milestone['creator']['login']] : '',
      'System.State' => 'New',
      'System.Description' => $this->converter->convertToHtml((string) $milestone['description'])->getContent() .
        sprintf("\n\n<p>Milestone migrated from Github: <a href=\"%s\">%d: %s</a></p>", $milestone['html_url'], $milestone['number'], $milestone['title']),
    ]);

    return $this->workItemsApi->workItemsCreate($this->targetOrg, json_encode($body, JSON_PRETTY_PRINT), $this->targetRepo, 'Epic', '6.0', 'False', 'True', 'True');
  }

  /**
   * Migrate issues.
   *
   * @return array
   *   List of Work Item Urls keyed by Github issue ID.
   */
  private function migrateIssues(OutputInterface $output, array $milestones): array {
    $per_page = 10;
    $page = 1;
    $results = [];
    do {
      $issues = $this->source->api('issue')->all($this->sourceOrg, $this->sourceRepo, [
        'sort' => 'created',
        'direction' => 'asc',
        'state' => 'all',
        'per_page' => $per_page,
        'page' => $page,
      ]);
      foreach ($issues as $issue) {
        if (!empty($issue['pull_request'])) {
          // Ignores PRs here.
          continue;
        }
        try {
          $workItem = $this->createWorkItemFromIssue($issue, $milestones, $results);
          $output->writeln(sprintf('<info>Migrated issue "%d" to User Story "%d" with %d comments.</info>', $issue['number'], $workItem->getId(), $issue['comments']));
          $results[$issue['number']] = [$workItem->getId(), $workItem->getUrl()];
        } catch (\Exception $e) {
          $output->writeln(sprintf('<error>Failed to migrate issue "%d" with next error: "%s".</error>', $issue['number'], $e->getMessage()));
        }
      }
      $page++;
    } while (!empty($issues));
    return $results;
  }

  /**
   * Create Azure working item based on Github Issue.
   *
   * @param array $issue
   * @param array $milestones
   * @param array $issues
   *
   * @return \FrankHouweling\AzureDevOpsClient\Wit\Model\WorkItem
   */
  private function createWorkItemFromIssue(array $issue, array $milestones, array $issues): WorkItem {
    $type = 'User Story';
    $tags = [];
    if (!empty($issue['labels'])) {
      foreach ($issue['labels'] as $label) {
        $tags[] = trim($label['name']);
      }
    }
    // Remove tag "bug" and set type Bug.
    if (in_array('bug', array_map('strtolower', $tags))) {
      unset($tags[array_search('bug', $tags)]);
      $type = 'Bug';
    }

    $body = $this->prepareFields([
      'System.Title' => $issue['title'],
      'System.CreatedDate' => $issue['created_at'],
      'System.ChangedDate' => $issue['created_at'],
      'Microsoft.VSTS.Common.StateChangeDate' => $issue['created_at'],
      'System.AuthorizedDate' => $issue['created_at'],
      'System.CreatedBy' => !empty($issue['user']) ? $this->getLocalUser($issue['user']['login']) : NULL,
      'System.AssignedTo' => !empty($issue['assignee']) ? $this->getLocalUser($issue['assignee']['login']) : NULL,
      'System.Description' => $this->replaceIssueIds($this->converter->convertToHtml((string) $issue['body'])
          ->getContent(), $issues, '#', TRUE) . sprintf("\n\n<div>Issue migrated from github <a href=\"%s\">#%s</a></div>", $issue['html_url'], $issue['number']),
      'System.Tags' => !empty($tags) ? implode('; ', $tags) : '',
    ]);

    // Add Parent relationship to milestone migrated as Epic.
    if (!empty($issue['milestone']) && !empty($milestones[$issue['milestone']['number']])) {
      $body[] = [
        'op' => 'add',
        'path' => "/relations/-",
        'value' => [
          "rel" => "System.LinkTypes.Hierarchy-Reverse",
          "url" => $milestones[$issue['milestone']['number']],
          "attributes" => [
            "name" => "Parent",
          ],
        ],
      ];
    }

    // Add Github issue relationship.
    if ($this->linkGithubIssue) {
      $body[] = [
        'op' => 'add',
        'path' => "/relations/-",
        'value' => [
          "rel" => "ArtifactLink",
          "url" => $issue['html_url'],
          "attributes" => [
            "name" => "GitHub Issue",
          ],
        ],
      ];
    }
    $commitUrls = $this->getReferencedCommits($issue);
    foreach ($commitUrls as $commitUrl) {
      $body[] = [
        'op' => 'add',
        'path' => "/relations/-",
        'value' => [
          "rel" => "ArtifactLink",
          "url" => $commitUrl,
          "attributes" => [
            "name" => "Fixed in Commit",
          ],
        ],
      ];
    }

    $workItem = $this->workItemsApi->workItemsCreate($this->targetOrg, json_encode($body, JSON_PRETTY_PRINT), $this->targetRepo, $type, '6.0', 'False', 'True', 'True');

    // Add comments.
    if ($issue['comments'] > 0) {
      $this->migrateComments($issue, $workItem, $issues);
    }
    // Update issue state if closed.
    elseif ($issue['state'] === 'closed') {
      $this->closeWorkItem($issue, $workItem);
    }

    return $workItem;
  }

  /**
   * Close Azure work item.
   *
   * @param array $issue
   * @param \FrankHouweling\AzureDevOpsClient\Wit\Model\WorkItem $workItem
   *
   * @throws \FrankHouweling\AzureDevOpsClient\Wit\ApiException
   */
  private function closeWorkItem(array $issue, WorkItem $workItem) {
    $ops = $this->prepareFields([
      'System.State' => 'Closed',
      'System.ChangedDate' => $issue['closed_at'],
      'System.ChangedBy' => !empty($issue['user']) ? $this->getLocalUser($issue['user']['login']) : NULL,
    ]);
    $this->workItemsApi->workItemsUpdate($this->targetOrg, json_encode($ops, JSON_PRETTY_PRINT), $workItem->getId(), $this->targetRepo, '6.0', 'False', 'True', 'True');
  }

  /**
   * Migrate Github issue comments to Azure DevOps work item.
   *
   * @param array $issue
   * @param \FrankHouweling\AzureDevOpsClient\Wit\Model\WorkItem $workItem
   * @param array $issues
   */
  private function migrateComments(array $issue, WorkItem $workItem, array $issues): void {
    $per_page = 10;
    $page = 1;
    do {
      $comments = $this->source->issue()->comments()->all($this->sourceOrg, $this->sourceRepo, $issue['number'], [
        'per_page' => $per_page,
        'page' => $page,
      ]);
      foreach ($comments as $comment) {
        if ($issue['state'] === 'closed' && (new \DateTime($issue['closed_at']) < new \DateTime($comment['created_at']))) {
          $this->closeWorkItem($issue, $workItem);
        }
        $text = $this->replaceIssueIds($this->converter->convertToHtml((string) $comment['body'])->getContent(), $issues, '#', TRUE);
        $author = $this->getLocalUser($comment['user']['login']);
        $body = $this->prepareFields([
          'System.History' => $text,
          'System.ChangedBy' => $author,
          'System.ChangedDate' => $comment['created_at'],
        ]);
        $this->workItemsApi->workItemsUpdate($this->targetOrg, json_encode($body, JSON_PRETTY_PRINT), $workItem->getId(), $this->targetRepo, '6.0', 'False', 'True', 'True');
      }
      $page++;
    } while (!empty($comments));
  }

  /**
   * Get Azure DevOps referenced commits from Github issue.
   *
   * @param array $issue
   *   Github issue.
   *
   * @return array
   *   Azure DevOps commits URLs.
   */
  private function getReferencedCommits(array $issue): array {
    $page = 1;
    $commits = [];
    do {
      $events = $this->source->issue()->events()->all($this->sourceOrg, $this->sourceRepo, $issue['number'], $page);
      foreach ($events as $event) {
        if (empty($event['commit_id'])) {
          continue;
        }
        try {
          $commitItem = $this->commitsApi->commitsGet($this->targetOrg, $event['commit_id'], $this->targetRepo, $this->targetRepo, '6.0');
          $parts = explode('/', ltrim(parse_url($commitItem->getUrl(), PHP_URL_PATH), '/'));
          $commits[] = "vstfs:///Git/Commit/{$parts[1]}/{$parts[5]}/{$parts[7]}";
        } catch (\Exception $e) {
          // Do nothing, commit doesn't exists in Azure.
        }
      }
      $page++;
    } while (!empty($events));
    return $commits;
  }

  /**
   * Migrate Pull Requests from Github to Azure.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   * @param array $issues
   *   List of migrated issues urls keyed by Github issue ID.
   *
   * @return array
   *   List of migrated PRs urls keyed by Github PR ID.
   */
  private function migratePullRequests(OutputInterface $output, array $issues): array {
    $per_page = 10;
    $page = 1;
    do {
      $prs = $this->source->pullRequests()->all($this->sourceOrg, $this->sourceRepo, [
        'direction' => 'asc',
        'per_page' => $per_page,
        'page' => $page,
      ]);
      foreach ($prs as $pr) {
        try {
          $prItem = $this->createPullRequest($pr, $issues);
          $comments = $this->migratePullRequestComments($pr, $prItem, $issues);
          $reviews = $this->migratePullRequestReviews($pr, $prItem, $issues);
          // TODO: Attach PRs to WorkItems

          $output->writeln(sprintf('<info>Migrated PR "%d" to "%d" with %d comments and %d reviews.</info>', $pr['number'], $prItem->getPullRequestId(), $comments, $reviews));
          $results[$pr['number']] = $prItem->getUrl();
        } catch (\Exception $e) {
          $output->writeln(sprintf('<error>Failed to migrate PR "%d" with next error: "%s".</error>', $pr['number'], $e->getMessage()));
        }
      }
      $page++;
    } while (!empty($prs));
    return $results;
  }

  /**
   * Create PR in Azure DevOps.
   *
   * @param array $pr
   * @param array $issues
   *
   * @return \FrankHouweling\AzureDevOpsClient\Git\Model\GitPullRequest
   */
  private function createPullRequest(array $pr, array $issues): GitPullRequest {
    $strings = mb_str_split($this->replaceIssueIds($this->converter->convertToHtml($pr['body'])->getContent(), $issues, '#', TRUE), 4000);
    $description = array_shift($strings);
    $body = [
      'sourceRefName' => "refs/heads/{$pr['head']['ref']}",
      'targetRefName' => "refs/heads/{$pr['base']['ref']}",
      'title' => $this->replaceIssueIds($pr['title'], $issues, 'GH-', FALSE),
      'creationDate' => $pr['created_at'],
      'createdBy' => $this->getLocalUser($pr['user']['login']),
      'status' => ['open' => 'active', 'closed' => 'completed'][$pr['state']],
      'isDraft' => $pr['draft'],
      'description' => $description,
    ];
    $prItem = $this->pullRequestApi->pullRequestsCreate($this->targetOrg, json_encode($body, JSON_PRETTY_PRINT), $this->targetRepo, $this->targetRepo, '6.0', 'True');
    // Put what's left as first thread.
    if (!empty($strings)) {
      $body = [
        'comments' => [
          [
            'parentCommentId' => 0,
            'content' => implode('', $strings),
            'author' => $this->getLocalUser($pr['user']['login']),
            'publishedDate' => $pr['created_at'],
            'commentType' => 1,
          ]
        ],
        'status' => 1,
      ];
      $this->pullRequestThreadsApi->pullRequestThreadsCreate($this->targetOrg, json_encode($body, JSON_PRETTY_PRINT), $this->targetRepo, $prItem->getPullRequestId(), $this->targetRepo, '6.0');
    }
    return $prItem;
  }

  /**
   * Migrate PR comments.
   *
   * @param array $pr
   * @param \FrankHouweling\AzureDevOpsClient\Git\Model\GitPullRequest $prItem
   * @param array $issues
   *
   * @return int
   *   Number of PR comments migrated.
   */
  private function migratePullRequestComments(array $pr, GitPullRequest $prItem, array $issues): int {
    $per_page = 10;
    $page = 1;
    $i = 0;
    do {
      $comments = $this->source->issue()->comments()->all($this->sourceOrg, $this->sourceRepo, $pr['number'], [
        'per_page' => $per_page,
        'page' => $page,
      ]);
      foreach ($comments as $comment) {
        $body = [
          'comments' => [
            [
              'parentCommentId' => 0,
              'content' => $this->replaceIssueIds($this->converter->convertToHtml((string) $comment['body'])->getContent(), $issues, '#', TRUE),
              'author' => $this->getLocalUser($pr['user']['login']),
              'publishedDate' => $comment['created_at'],
              'commentType' => 1,
            ]
          ],
          'status' => 1,
        ];
        $this->pullRequestThreadsApi->pullRequestThreadsCreate($this->targetOrg, json_encode($body, JSON_PRETTY_PRINT), $this->targetRepo, $prItem->getPullRequestId(), $this->targetRepo, '6.0');
        $i++;
      }
      $page++;
    } while (!empty($comments));
    return $i;
  }

  /**
   * Migrate PR reviews.
   *
   * @param array $pr
   * @param \FrankHouweling\AzureDevOpsClient\Git\Model\GitPullRequest $prItem
   * @param array $issues
   *
   * @return int
   *   Number of PR reviews migrated.
   */
  private function migratePullRequestReviews(array $pr, GitPullRequest $prItem, array $issues): int {
    $per_page = 10;
    $page = 1;
    $i = 0;
    do {
      $reviews = $this->source->api('pull_request')->reviews()->all($this->sourceOrg, $this->sourceRepo, $pr['number'], [
        'per_page' => $per_page,
        'page' => $page,
      ]);
      foreach ($reviews as $review) {
        $thread = NULL;
        if (!empty($review['body'])) {
          $body = [
            'comments' => [
              [
                'parentCommentId' => 0,
                'content' => $this->replaceIssueIds($this->converter->convertToHtml((string) $review['body'])->getContent(), $issues, '#', TRUE),
                'author' => $this->getLocalUser($pr['user']['login']),
                'publishedDate' => $review['submitted_at'],
                'commentType' => 1,
              ]
            ],
            'status' => 1,
          ];
          $this->pullRequestThreadsApi->pullRequestThreadsCreate($this->targetOrg, json_encode($body, JSON_PRETTY_PRINT), $this->targetRepo, $prItem->getPullRequestId(), $this->targetRepo, '6.0');
          $i++;
        }

        $comments = $this->source->api('pull_request')->reviews()->comments($this->sourceOrg, $this->sourceRepo, $pr['number'], $review['id']);
        foreach ($comments as $comment) {
          $line = 1;
          $offset = 1;
          if (!empty($comment['diff_hunk'])) {
            // Doing some magic here:
            // 1. take diff_hunk which is simple patch format.
            // 2. extract line number from first line, ex: "@@ -25,7 +25,11 @@"
            // 3. Find first line that begins with "-" and add index to line num.
            $diff_hunk_lines = explode("\n", $comment['diff_hunk']);
            $patch_head_line = array_shift($diff_hunk_lines);
            $line = floatval(explode(' ', $patch_head_line)[1]) * -1;
            foreach ($diff_hunk_lines as $i => $diff_hunk_line) {
              !empty($diff_hunk_line[0]) && $diff_hunk_line[0] === '-';
              $line += $i;
              break;
            }
          }
          $body = [
            'comments' => [
              [
                'parentCommentId' => 0,
                'content' => $this->replaceIssueIds($this->converter->convertToHtml((string) $comment['body'])->getContent(), $issues, '#', TRUE),
                'author' => $this->getLocalUser($pr['user']['login']),
                'publishedDate' => $comment['created_at'],
                'commentType' => 1,
              ],
            ],
            'status' => 1,
            'threadContext' => [
              'filePath' => $comment['path'],
              'rightFileEnd' => [
                'line' => $line,
                'offset' => $offset,
              ],
              'rightFileStart' => [
                'line' => $line,
                'offset' => $offset,
              ],
            ],
          ];
          $this->pullRequestThreadsApi->pullRequestThreadsCreate($this->targetOrg, json_encode($body, JSON_PRETTY_PRINT), $this->targetRepo, $prItem->getPullRequestId(), $this->targetRepo, '6.0');
          $i++;
        }
      }
      $page++;
    } while (!empty($reviews));
    return $i;
  }

}
