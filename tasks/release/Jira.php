<?php

namespace MailPoetTasks\Release;

class Jira {

  const CHANGELOG_FIELD_ID = 'customfield_10500';
  const RELEASENOTE_FIELD_ID = 'customfield_10504';

  const WONT_DO_RESOLUTION_ID = '10001';

  const PROJECT_MAILPOET = 'MAILPOET';
  const PROJECT_PREMIUM = 'PREMIUM';

  const JIRA_DOMAIN = 'mailpoet.atlassian.net';
  const JIRA_API_VERSION = '3';

  /** @var string */
  private $token;

  /** @var string */
  private $user;

  /** @var string */
  private $project;

  public function __construct($token, $user, $project) {
    $this->token = $token;
    $this->user = $user;
    $this->project = $project;
  }

  /**
   * @see https://developer.atlassian.com/cloud/jira/platform/rest/v3/#api-api-3-project-projectIdOrKey-versions-get
   */
  function getVersion($version_name = null) {
    $versions = $this->makeJiraRequest("project/$this->project/versions");
    if ($version_name === null) {
      return end($versions);
    }
    foreach ($versions as $version) {
      if ($version_name === $version['name']) {
        return $version;
      }
    }
    throw new \Exception('Unknown project version');
  }

  /**
   * @see https://developer.atlassian.com/cloud/jira/platform/rest/v3/#api-api-3-version-post
   */
  function createVersion($version_name) {
    $data = [
      'name' => $version_name,
      'archived' => false,
      'released' => false,
      'project' => $this->project,
    ];
    return $this->makeJiraRequest('/version', 'POST', $data);
  }

  function getIssuesDataForVersion($version) {
    $changelog_id = self::CHANGELOG_FIELD_ID;
    $release_note_id = self::RELEASENOTE_FIELD_ID;
    $issues_data = $this->search("fixVersion={$version['id']}", ['key', $changelog_id, $release_note_id, 'status', 'resolution']);
    // Sort issues by importance of change (Added -> Updated -> Improved -> Changed -> Fixed -> Others)
    usort($issues_data['issues'], function($a, $b) use ($changelog_id) {
      $order = array_flip(['added', 'updat', 'impro', 'chang', 'fixed']);
      $a_prefix = strtolower(substr($a['fields'][$changelog_id], 0, 5));
      $b_prefix = strtolower(substr($b['fields'][$changelog_id], 0, 5));
      $a_rank = isset($order[$a_prefix]) ? $order[$a_prefix] : count($order);
      $b_rank = isset($order[$b_prefix]) ? $order[$b_prefix] : count($order);
      return $a_rank - $b_rank;
    });
    return $issues_data['issues'];
  }

  /**
   * @see https://developer.atlassian.com/cloud/jira/platform/rest/v3/#api-api-3-search-get
   */
  function search($jql, array $fields = null) {
    $params = ['jql' => $jql];
    if ($fields) {
      $params['fields'] = join(',', $fields);
    }
    return $this->makeJiraRequest("/search?" . http_build_query($params));
  }

  /**
   * @see https://developer.atlassian.com/cloud/jira/platform/rest/v3/#api-api-3-issue-issueIdOrKey-put
   */
  function updateIssue($key, $data) {
    return $this->makeJiraRequest("/issue/$key", 'PUT', $data);
  }

  private function makeJiraRequest($path, $method = 'GET', array $data = null) {
    $url_user = urlencode($this->user);
    $url_token = urlencode($this->token);
    $jira_domain = self::JIRA_DOMAIN;
    $jira_api_version = self::JIRA_API_VERSION;
    $jira_url = "https://$url_user:$url_token@$jira_domain/rest/api/$jira_api_version/$path";
    $options = [];
    if ($method === 'POST' || $method === 'PUT') {
      $options = [
        'http' => [
          'method' => $method,
          'header' => "Content-type: application/json\r\n",
          'content' => json_encode($data),
        ]
      ];
    }
    $context  = stream_context_create($options);
    $result = file_get_contents($jira_url, false, $context);
    if ($result === false) {
      $error = error_get_last();
      throw new \Exception('JIRA request error: ' . $error['message']);
    }
    return json_decode($result, true);
  }
}
