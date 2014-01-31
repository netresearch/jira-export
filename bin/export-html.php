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
 * @link   https://docs.atlassian.com/jira/REST/4.4.3/
 * @link   https://docs.atlassian.com/jira/REST/5.1/
 */
require_once 'Console/CommandLine.php';
require_once 'HTTP/Request2.php';

$parser = new Console_CommandLine();
$parser->description = 'Export JIRA issues to static HTML files';
$parser->version = '0.1.0';
$parser->addOption('config', array(
    'short_name'  => '-c',
    'long_name'   => '--config',
    'description' => 'path to configuration FILE',
    'help_name'   => 'FILE',
    'action'      => 'StoreString',
    'default'     => __DIR__ . '/../data/config.php'
));
try {
    $result = $parser->parse();
    $configFile = $result->options['config'];
} catch (Exception $exc) {
    $parser->displayError($exc->getMessage());
}

require_once $configFile;

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

class PagingJsonIterator implements Iterator
{
    protected $http;
    protected $url;
    protected $pageSize;
    protected $totalCount;

    public $jsonVarTotal = 'total';
    public $jsonVarStartAt = 'startAt';
    public $jsonVarPageSize = 'maxResults';
    public $jsonVarData = 'issues';

    public function __construct(HTTP_Request2 $http, $url, $pageSize = 50)
    {
        $this->http = $http;
        $this->url = $url;
        $this->pageSize = $pageSize;
    }

    public function rewind()
    {
        $this->position = 0;
        $this->loadData();
    }

    public function current()
    {
        return $this->data[$this->position - $this->dataPos];
    }

    public function key()
    {
        return $this->position;
    }

    public function next()
    {
        ++$this->position;
    }

    public function valid()
    {
        if (isset($this->data[$this->position - $this->dataPos])) {
            return true;
        }
        if ($this->position >= $this->totalCount) {
            //no more data
            return false;
        }
        $this->loadData();
        return isset($this->data[$this->position - $this->dataPos]);
    }

    protected function loadData()
    {
        $obj = fetch_json(
            str_replace(
                array('{startAt}', '{pageSize}'),
                array($this->position, $this->pageSize),
                $this->url
            )
        );
        $this->totalCount = $obj->{$this->jsonVarTotal};
        $this->data = $obj->{$this->jsonVarData};
        $this->dataPos = $this->position;
    }
}

function fetch_json($url)
{
    global $http;
    $hreq = clone $http;
    $res = $hreq->setUrl($url)->send();

    if (intval($res->getStatus() / 100) > 2) {
        echo sprintf("Error fetching data from %s\n", $hreq->getUrl());
        echo sprintf(
            "Status: %s %s\n", $res->getStatus(), $res->getReasonPhrase()
        );
        exit(2);
    }

    list($contentType) = explode(';', $res->getHeader('content-type'));
    if ($contentType !== 'application/json') {
        echo sprintf("Error fetching data from %s\n", $hreq->getUrl());
        echo "Content type is not application/json but "
            . $contenType . "\n";
        exit(3);
    }

    $json = json_decode($res->getBody());
    return $json;
}

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
        $pi = new PagingJsonIterator(
            $http,
            $jira_url . 'rest/api/latest/search'
            . '?startAt={startAt}'
            . '&maxResults={pageSize}'
            . '&fields=key'
            . '&jql=' . urlencode(
                'updated >= "' . date('Y-m-d H:i', $lutime) . '"'
            ),
            500
        );
        foreach ($pi as $issue) {
            list($pkey,) = explode('-', $issue->key);
            $updatedProjects[$pkey] = true;
        }
        ksort($updatedProjects);
        $hasUpdated = count($updatedProjects) > 0;
        echo sprintf(" Found %d projects.\n", count($updatedProjects));
        if (!$hasUpdated) {
            exit();
        }
    }
}

//fetch projects
$projects = fetch_json($jira_url . 'rest/api/latest/project');
createProjectIndex($projects);
foreach ($projects as $project) {
    if ($hasUpdated && !isset($updatedProjects[$project->key])) {
        continue;
    }
    echo sprintf("%s - %s\n", $project->key, $project->name);
    //fetch all issues
    $pi = new PagingJsonIterator(
        $http,
        $jira_url . 'rest/api/latest/search'
        . '?startAt={startAt}'
        . '&maxResults={pageSize}'
        . '&fields=key,updated,summary,parent'
        . '&jql=project%3D%22' . urlencode($project->key) . '%22',
        500
    );

    createIssueIndex($pi, $project);
    downloadIssues($pi, $project);
}

//so we only have to update next time instead of exporting everything again
file_put_contents($lufile, date('c', $start));

function downloadIssues(Iterator $issues, $project)
{
    global $http, $jira_url, $export_dir;

    echo ' ';
    foreach ($issues as $issue) {
        if (!isset($issue->key) || $issue->key == '') {
            echo 'x';
            continue;
        }

        $file = $export_dir . $issue->key . '.html';

        if (file_exists($file) && isset($issue->fields->updated)) {
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
            if (isset($project->avatarUrls->{'16x16'})) {
                $icon = sprintf(
                    '<img src="%s" alt="" width="16" height="16"/> ',
                    htmlspecialchars($project->avatarUrls->{'16x16'})
                );
            } else {
                //jira 4.4 rest-api v1
                $icon = '';
            }

            $body .= '<tr>'
                . '<td>'
                . $icon
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

function createIssueIndex(Iterator $issues, $project)
{
    global $export_dir, $htmlTemplate, $jira_url, $topbar;

    $title = sprintf('%s: %s', $project->key, $project->name);

    $purl = $jira_url . 'browse/' . $project->key;
    $body = '<h1>' . $title . "</h1>\n"
        . '<p><a href="' . htmlspecialchars($purl) . '">'
        . htmlspecialchars($project->key) . ' in Jira'
        . "</a></p>\n";

    $arIssues = array();
    $count = 0;
    foreach ($issues as $issue) {
        ++$count;
        if (isset($issue->fields->parent)) {
            $arIssues[$issue->fields->parent->key]['children']
                [$issue->key]['issue'] = $issue;
        } else {
            $arIssues[$issue->key]['issue'] = $issue;
        }
    }
    echo sprintf(" %d issues\n", $count);

    $body .= renderIssuesList($arIssues);
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

function renderIssuesList($arIssues)
{
    $html = "<ul>\n";
    uksort($arIssues, 'strnatcmp');
    foreach ($arIssues as $arIssue) {
        $issue = $arIssue['issue'];
        if (!isset($issue->fields)) {
            //jira 4.4 has no summary
            $issue->fields = (object) array(
                'key'     => $issue->key,
                'summary' => ''
            );
        }

        $html .= '<li>'
            . '<a href="' . $issue->key . '.html">'
            . $issue->key . ': '
            . htmlspecialchars($issue->fields->summary)
            . '</a>';
        if (isset($arIssue['children']) && count($arIssue['children'])) {
            $html .= renderIssuesList($arIssue['children']);
        }
        $html .= "</li>\n";
    }
    $html .= "</ul>\n";
    return $html;
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
        return strnatcasecmp($aC, $bC);
    } else {
        return strnatcasecmp($a->name, $b->name);
    }
}

function getLastUpdateHtml()
{
    return '<p>Last update: ' . date('c') . "</p>\n";
}

function adjustIssueHtml($html, $project)
{
    global $jira_url, $topbar;

    if (!isset($project->id)) {
        //jira 4.4
        preg_match('#BrowseProject\\.jspa\\?id=([0-9]+)#', $html, $matches);
        $project->id = $matches[1];
    }

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
