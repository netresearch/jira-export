#!/usr/bin/env php
<?php
/**
 * Export all issues from JIRA into static HTML files.
 * They can then be indexed by the search engine.
 *
 * Note that we do not link to .html files since I want to be able
 * to map static => real JIRA URLs by replacing a prefix.
 *
 * @package JiraExport
 * @author  Christian Weiske <christian.weiske@netresearch.de>
 * @license AGPL https://www.gnu.org/licenses/agpl
 * @link    https://docs.atlassian.com/jira/REST/4.4.3/
 * @link    https://docs.atlassian.com/jira/REST/5.1/
 */
require_once 'Console/CommandLine.php';
require_once 'HTTP/Request2.php';

$parser = new Console_CommandLine();
$parser->description = 'Export JIRA issues to static HTML files';
$parser->version = '0.2.0';
$parser->addOption('config', array(
    'short_name'  => '-c',
    'long_name'   => '--config',
    'description' => 'path to configuration FILE',
    'help_name'   => 'FILE',
    'action'      => 'StoreString',
    'default'     => __DIR__ . '/../data/config.php'
));
$parser->addOption('silent', array(
    'short_name'  => '-s',
    'long_name'   => '--silent',
    'description' => 'Do not output status messages',
    'action'      => 'StoreTrue',
    'default'     => false,
));
try {
    $result = $parser->parse();
    $configFile = $result->options['config'];
    $silent = $result->options['silent'];
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

function logError($msg)
{
    file_put_contents('php://stderr', $msg);
}

function doLog($msg)
{
    global $silent;
    if (!$silent) {
        echo $msg;
    }
}
function fetch_json($url)
{
    global $http;
    $hreq = clone $http;
    $res = $hreq->setUrl($url)->send();

    if (intval($res->getStatus() / 100) > 2) {
        logError(sprintf("Error fetching data from %s\n", $hreq->getUrl()));
        logError(
            sprintf(
                "Status: %s %s\n", $res->getStatus(), $res->getReasonPhrase()
            )
        );
        exit(2);
    }

    list($contentType) = explode(';', $res->getHeader('content-type'));
    if ($contentType !== 'application/json') {
        logError(sprintf("Error fetching data from %s\n", $hreq->getUrl()));
        logError(
            "Content type is not application/json but "
            . $contenType . "\n"
        );
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
    doLog("Fetching projects updated since last export\n");
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
            if (count($allowedProjectKeys) == 0
                || array_search($pkey, $allowedProjectKeys)
            ) {
                $updatedProjects[$pkey] = true;
            }
        }
        ksort($updatedProjects);
        $hasUpdated = count($updatedProjects) > 0;
        doLog(sprintf(" Found %d projects.\n", count($updatedProjects)));
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
    doLog(sprintf("%s - %s\n", $project->key, $project->name));
    if (count($allowedProjectKeys) != 0
        && !array_search($project->key, $allowedProjectKeys)
    ) {
        doLog(" skip\n");
        continue;
    }
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

    doLog(' ');
    foreach ($issues as $issue) {
        if (!isset($issue->key) || $issue->key == '') {
            doLog('x');
            continue;
        }

        $file = $export_dir . $issue->key . '.html';

        if (file_exists($file) && isset($issue->fields->updated)) {
            $iDate = strtotime($issue->fields->updated);
            $fDate = filemtime($file);
            if ($iDate < $fDate) {
                doLog('.');
                continue;
            }
        }

        doLog('n');
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
    doLog("\n");
}

function createProjectIndex($projects)
{
    global $allowedProjectKeys,
        $export_dir, $htmlTemplate, $jira_url, $topbar;

    $categories = array();
    foreach ($projects as $project) {
        if (count($allowedProjectKeys) != 0
            && !array_search($project->key, $allowedProjectKeys)
        ) {
            continue;
        }
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
    doLog(sprintf(" %d issues\n", $count));

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
        if (empty($arIssue['issue'])) {
            continue;
        }
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
        '<body>',
        <<<CSS
<style type="text/css">
/* jira 5.1 */
.aui-toolbar .toolbar-split.toolbar-split-right {
    float: none;
}
.aui-toolbar .toolbar-group {
    margin-bottom: 0px;
}
#previous-view, .previous-view {
    padding-top: 16px;
    padding-bottom: 5px;
}
/* jira 4.4 */
.previous-view > a {
    background: linear-gradient(to bottom,#fff 0,#f2f2f2 100%);
    border-color: #ccc;
    border-style: solid;
    border-width: 1px;
    color: #333;
    font-weight: normal;
    display: inline-block;
    margin: 0;
    padding: 4px 10px;
    border-top-left-radius: 3px;
    border-bottom-left-radius: 3px;
}
</style>
CSS
        . '<link rel="up" href="' . $project->key . '.html"/>'
        . '<link rel="index" href="' . $project->key . '.html"/>'
        . '<body>'
        . $topbar,
        $html
    );
    $html = str_replace(
        array(
            'Back to previous view',
            '&lt;&lt; Zur vorherigen Ansicht'
        ),
        'View issue in Jira', $html
    );
    $html = str_replace(
        //make project link local
        'href="' . $jira_url . 'secure/BrowseProject.jspa?id=' . $project->id . '"',
        'href="' . $project->key . '.html"',
        $html
    );

    if (strpos($html, '<li class="toolbar-item">') === false) {
        //jira 4.4
        $html = str_replace(
            '<div class="previous-view">',
            '<div class="previous-view">'
            . '<a href="' . $project->key . '.html">All issues</a>',
            $html
        );
    } else {
        //jira 5.1
        $html = str_replace(
            //add project link
            '<li class="toolbar-item">',
            '<li class="toolbar-item">'
            . '<a href="' . $project->key . '.html" class="toolbar-trigger">All issues</a>'
            . '</li>'
            . '<li class="toolbar-item">',
            $html
        );
    }

    //make issue links local (but not "View issue in Jira" link)
    //dependencies
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML($html);
    $xpath   = new DOMXpath($doc);
    $anchors = $xpath->query("/html/body/table//a[@href]");
    $prefix  = $jira_url . 'browse/';
    foreach ($anchors as $anchor) {
        $href = $anchor->getAttribute("href");
        if (substr($href, 0, strlen($prefix)) != $prefix) {
            continue;
        }
        preg_match('#browse/([A-Z]+-[0-9]+)#', $href, $matches);
        if (isset($matches[1])) {
            $issueId = $matches[1];
            $anchor->setAttribute("href", $issueId . '.html');
        }
    }
    $html = $xpath->document->saveHTML();

    return $html;
}
?>
