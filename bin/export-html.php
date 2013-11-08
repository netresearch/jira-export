#!/usr/bin/env php
<?php
/**
 * Export all issues from JIRA into static HTML files.
 * They can then be indexed by the search engine.
 *
 * @author Christian Weiske <christian.weiske@netresearch.de>
 */
require_once __DIR__ . '/../data/config.php';
require_once 'HTTP/Request2.php';

$http = new HTTP_Request2();
$http->setConfig(
    array(
        'ssl_verify_peer' => false
    )
);
$http->setAuth($jira_user, $jira_password, HTTP_Request2::AUTH_BASIC);

//fetch projects
$hpr = clone $http;
$pres = $hpr->setUrl($jira_url . 'rest/api/2/project')->send();
$projects = json_decode($pres->getBody());
foreach ($projects as $project) {
    echo sprintf("%s - %s\n", $project->key, $project->name);
    //fetch all issues
    $hi = clone $http;
    $ires = $hi->setUrl(
        $jira_url . 'rest/api/2/search'
        . '?startAt=0'
        . '&maxResults=1000'
        . '&fields=key,updated,summary'
        . '&jql=project%3D' . urlencode($project->key)
    )->send();
    $issues = json_decode($ires->getBody())->issues;
    echo sprintf(" %d issues\n", count($issues));
    echo ' ';
    foreach ($issues as $issue) {
        echo '.';
        $hd = clone $http;
        $idres = $hd->setUrl(
            $jira_url . 'si/jira.issueviews:issue-html/'
            . $issue->key . '/' . $issue->key . '.html'
        )->send();
        //FIXME: check response type
        file_put_contents(
            $export_dir . $issue->key . '.html',
            $idres->getBody()
        );
    }
    echo "\n";
}
?>
