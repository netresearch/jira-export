************************
Static JIRA issue export
************************

Export all issues of a JIRA issue tracker instance into static
HTML files.

This static files can be indexed by an intranet search engine
easily, without having to setup autologin in JIRA.


=====
Setup
=====
#. Clone git repository
#. ``$ cp data/config.php.dist /data/config.php``
#. Adjust ``data/config.php``
#. Install dependencies
#. Run the initial import: ``$ ./bin/export-html.php``
#. Setup the web server document root to ``www/``
#. Setup cron to run the export every night


============
Dependencies
============

* PHP
* HTTP_Request2 from PEAR::

    $ pear install http_request2


=============
Similar tools
=============

* `Gigan`__ - Parse JIRA XML into CouchDB

__ https://github.com/janl/gigan


=================
About jira-export
=================

License
=======
``jira-export`` is licensed under the `AGPL v3`__ or later.

__ http://www.gnu.org/licenses/agpl


Author
======
`Christian Weiske`__, `Netresearch GmbH & Co KG`__

__ mailto:christian.weiske@netresearch.de
__ http://www.netresearch.de/
