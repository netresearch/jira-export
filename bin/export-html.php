#!/usr/bin/env php
<?php
/**
 * Export all issues from JIRA into static HTML files.
 * They can then be indexed by the search engine.
 *
 * Note that we do not link to .html files since I want to be able
 * to map static => real JIRA URLs by replacing a prefix.
 *
 * @author Christian Weiske <christian.weiske@netresearch.de>
 */
require_once __DIR__ . '/../data/config.php';
require_once 'HTTP/Request2.php';

$htmlTemplate = <<<HTM
<?xml version="1.0" encoding="utf-8"?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
 <head>
  <title>%TITLE%</title>
 </head>
 <body>
%BODY%
 </body>
</html>

HTM;

$start = time();

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
createProjectIndex($projects);
foreach ($projects as $project) {
    echo sprintf("%s - %s\n", $project->key, $project->name);
    //fetch all issues
    $hi = clone $http;
    $ires = $hi->setUrl(
        $jira_url . 'rest/api/2/search'
        . '?startAt=0'
        . '&maxResults=1000'
        . '&fields=key,updated,summary,parent'
        . '&jql=project%3D%22' . urlencode($project->key) . '%22'
    )->send();

    $obj = json_decode($ires->getBody());
    if (!isset($obj->issues)) {
        continue;
    }

    $issues = $obj->issues;
    echo sprintf(" %d issues\n", count($issues));
    createIssueIndex($project, $issues);
    downloadIssues($issues);
}

//so we only have to update next time instead of exporting everything again
file_put_contents($export_dir . 'last-update', date('c', $start));

function downloadIssues(array $issues)
{
    global $http, $jira_url, $export_dir;

    echo ' ';
    foreach ($issues as $issue) {
        $file = $export_dir . $issue->key . '.html';

        if (file_exists($file)) {
            $iDate = strtotime($issue->fields->updated);
            $fDate = filemtime($file);
            if ($iDate < $fDate) {
                echo '.';
                continue;
            }
        }

        echo 'n';
        $hd = clone $http;
        $idres = $hd->setUrl(
            $jira_url . 'si/jira.issueviews:issue-html/'
            . $issue->key . '/' . $issue->key . '.html'
        )->send();
        //FIXME: check response type
        file_put_contents($file, $idres->getBody());
    }
    echo "\n";
}

function createProjectIndex($projects)
{
    global $export_dir, $htmlTemplate;

    $categories = array();
    foreach ($projects as $project) {
        list($category) = explode(':', $project->name);
        $categories[$category][] = $project;
    }
    foreach (array_keys($categories) as $category) {
        if (count($categories[$category]) == 1) {
            $categories['others'][] = $categories[$category][0];
            unset($categories[$category]);
        }
    }

    $title = 'JIRA project list';
    $body = '<h1>' . $title . "</h1>\n";

    $lastCategory = null;
    foreach ($categories as $category => $projects) {
        $body .= '<h2>' . htmlspecialchars($category) . '</h2>'
            . "<table border='0'>\n";
        usort($projects, 'compareProjects');
        foreach ($projects as $project) {
            $body .= '<tr>'
                . '<td>'
                . sprintf(
                    '<img src="%s" alt="" width="16" height="16"/> ',
                    htmlspecialchars($project->avatarUrls->{'16x16'})
                )
                . '</td>'
                . '<td>'
                . '<a href="' . $project->key . '">'
                . $project->key
                . '</a>'
                . '</td>'
                . '<td>'
                . '<a href="' . $project->key . '">'
                . htmlspecialchars($project->name)
                . '</a>'
                . "</td></tr>\n";
        }
        $body .= "</table>\n";
    }

    $html = str_replace('%TITLE%', $title, $htmlTemplate);
    file_put_contents(
        $export_dir . 'index.html',
        str_replace('%BODY%', $body, $html)
    );
}

function createIssueIndex($project, $issues)
{
    global $export_dir, $htmlTemplate;

    $title = sprintf('%s: %s', $project->key, $project->name);
    $body = '<h1>' . $title . "</h1>\n"
        . "<ul>\n";

    usort($issues, 'compareIssuesByParentAndKey');
    $bInParent = false;
    $bClose = false;
    foreach ($issues as $issue) {
        if (isset($issue->fields->parent) && !$bInParent) {
            $body .= "<ul>\n";
            $bInParent = true;
        } else if (!isset($issue->fields->parent) && $bInParent) {
            if ($bClose) {
                $body .= "</li>\n";
            }
            $body .= "</ul>\n</li>\n";
            $bInParent = false;
        } else if ($bClose) {
            $body .= "</li>\n";
        }
        $body .= '<li>'
            . '<a href="' . $issue->key . '">'
            . $issue->key . ': '
            . htmlspecialchars($issue->fields->summary)
            . '</a>';
        $bClose = true;
    }
    if ($bClose) {
        $body .= "</li>\n";
    }
    $body .= "</ul>\n";

    $html = str_replace('%TITLE%', $title, $htmlTemplate);
    file_put_contents(
        $export_dir . $project->key . '.html',
        str_replace('%BODY%', $body, $html)
    );
}

/**
 * Jira does not support retrieving the project category via the API:
 * https://jira.atlassian.com/browse/JRA-30001
 * So we split the title by ":", assuming that the category/customer name
 * is in there like "Customer name: Project title"
 */
function compareProjects($a, $b)
{
    list($aC) = explode(':', $a->name, 2);
    list($bC) = explode(':', $b->name, 2);

    if ($aC != $bC) {
        return strnatcmp($aC, $bC);
    } else {
        return strnatcmp($a->name, $b->name);
    }
}

function compareIssuesByParentAndKey($a, $b)
{
    if (isset($a->fields->parent) && isset($b->fields->parent)) {
        if ($a->fields->parent->key == $b->fields->parent->key) {
            return strnatcmp($a->key, $b->key);
        }
        return strnatcmp($a->fields->parent->key, $b->fields->parent->key);
    } else if (isset($a->fields->parent)) {
        if ($a->fields->parent->key == $b->key) {
            return 1;
        }
        return strnatcmp($a->fields->parent->key, $b->key);
    } else if (isset($b->fields->parent)) {
        if ($a->key == $b->fields->parent->key) {
            return -1;
        }
        return strnatcmp($a->key, $b->fields->parent->key);
    } else {
        return strnatcmp($a->key, $b->key);
    }
}
?>
