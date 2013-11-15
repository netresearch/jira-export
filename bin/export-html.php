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
  <link rel="index" href="./"/>
  <style type="text/css">
html, body {
    margin: 0px;
    padding: 0px;
    border: none;
}
#content {
    margin: 2ex;
}
  </style>
 </head>
 <body>
  %TOPBAR%
  <div id="content">
%BODY%
  </div>
 </body>
</html>

HTM;

$lufile = $export_dir . 'last-update';
$start = time();

$http = new HTTP_Request2();
$http->setConfig(
    array(
        'ssl_verify_peer' => false
    )
);
$http->setAuth($jira_user, $jira_password, HTTP_Request2::AUTH_BASIC);

$updatedProjects = array();
$hasUpdated = false;
if (file_exists($lufile)) {
    //fetch list of projects updated since last export
    $lutime = strtotime(file_get_contents($lufile));
    echo "Fetching projects updated since last export\n";
    if (time() - $lutime <= 14 * 86400) {
        $hpr = clone $http;
        $pres = $hpr->setUrl(
            $jira_url . 'rest/api/2/search'
            . '?startAt=0'
            . '&maxResults=1000'
            . '&fields=key'
            . '&jql=' . urlencode(
                'updated >= "' . date('Y-m-d H:i', $lutime) . '"'
            )
        )->send();
        $upissues = json_decode($pres->getBody());
        foreach ($upissues->issues as $issue) {
            list($pkey,) = explode('-', $issue->key);
            $updatedProjects[$pkey] = true;
        }
        ksort($updatedProjects);
        $hasUpdated = count($updatedProjects) > 0;
        echo sprintf(" Found %d projects.\n", count($updatedProjects));
    }
}

//fetch projects
$hpr = clone $http;
$pres = $hpr->setUrl($jira_url . 'rest/api/2/project')->send();
$projects = json_decode($pres->getBody());
createProjectIndex($projects);
foreach ($projects as $project) {
    if ($hasUpdated && !isset($updatedProjects[$project->key])) {
        continue;
    }
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
    createIssueIndex($issues, $project);
    downloadIssues($issues, $project);
}

//so we only have to update next time instead of exporting everything again
file_put_contents($lufile, date('c', $start));

function downloadIssues(array $issues, $project)
{
    global $http, $jira_url, $export_dir;

    echo ' ';
    foreach ($issues as $issue) {
        if (!isset($issue->key) || $issue->key == '') {
            echo 'x';
            continue;
        }

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

        file_put_contents(
            $file,
            adjustIssueHtml($idres->getBody(), $project)
        );
    }
    echo "\n";
}

function createProjectIndex($projects)
{
    global $export_dir, $htmlTemplate, $jira_url, $topbar;

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
    $body = '<h1>' . $title . "</h1>\n"
        . '<p><a href="' . htmlspecialchars($jira_url) . '">'
        . htmlspecialchars($jira_url)
        . "</a></p>\n";

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
                . '<a href="' . $project->key . '.html">'
                . $project->key
                . '</a>'
                . '</td>'
                . '<td>'
                . '<a href="' . $project->key . '.html">'
                . htmlspecialchars($project->name)
                . '</a>'
                . "</td></tr>\n";
        }
        $body .= "</table>\n";
    }

    $body .= getLastUpdateHtml();

    $html = str_replace('%TITLE%', $title, $htmlTemplate);
    file_put_contents(
        $export_dir . 'index.html',
        str_replace(
            array('%TOPBAR%', '%BODY%'),
            array($topbar, $body),
            $html
        )
    );
}

function createIssueIndex($issues, $project)
{
    global $export_dir, $htmlTemplate, $jira_url, $topbar;

    $title = sprintf('%s: %s', $project->key, $project->name);

    $purl = $jira_url . 'browse/' . $project->key;
    $body = '<h1>' . $title . "</h1>\n"
        . '<p><a href="' . htmlspecialchars($purl) . '">'
        . htmlspecialchars($project->key) . ' in Jira'
        . "</a></p>\n"
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
            . '<a href="' . $issue->key . '.html">'
            . $issue->key . ': '
            . htmlspecialchars($issue->fields->summary)
            . '</a>';
        $bClose = true;
    }
    if ($bClose) {
        $body .= "</li>\n";
    }
    $body .= "</ul>\n";

    $body .= getLastUpdateHtml();

    $html = str_replace('%TITLE%', $title, $htmlTemplate);
    file_put_contents(
        $export_dir . $project->key . '.html',
        str_replace(
            array('%TOPBAR%', '%BODY%'),
            array($topbar, $body),
            $html
        )
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

function getLastUpdateHtml()
{
    return '<p>Last update: ' . date('c') . "</p>\n";
}

function adjustIssueHtml($html, $project)
{
    global $jira_url, $topbar;
    $html = str_replace(
        array(
            '<body>',
            'Back to previous view',
            //make project link local
            'href="' . $jira_url . 'secure/BrowseProject.jspa?id=' . $project->id . '"',
            //add project link
            '<li class="toolbar-item">'
        ),
        array(
            '<link rel="up" href="' . $project->key . '.html"/>'
            . '<link rel="index" href="' . $project->key . '.html"/>'
            . '<body>'
            . $topbar,
            'View issue in Jira',
            'href="' . $project->key . '.html"',
            '<li class="toolbar-item">'
            . '<a href="' . $project->key . '.html" class="toolbar-trigger">All issues</a>'
            . '</li>'
            . '<li class="toolbar-item">'
        ),
        $html
    );

    //make issue links local (but not "View issue in Jira" link)
    //dependencies
    $html = preg_replace(
        '#href="' . preg_quote($jira_url) . 'browse/([A-Z]+-[0-9]+)">#',
        'href="\\1.html">',
        $html
    );
    //issues within comments
    $html = preg_replace(
        '#href="' . preg_quote($jira_url) . 'browse/([A-Z]+-[0-9]+)" title=#',
        'href="\\1.html" title=',
        $html
    );

    return $html;
}
?>
