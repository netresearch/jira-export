# Static JIRA issue export, updated for 2022

Export all issues of a JIRA issue tracker instance into static HTML
files.

This static files can be indexed by an intranet search engine easily,
without having to setup autologin in JIRA.

The first export will take quite some time. After that initial run, only
projects with modifications since the last export will get updated,
which makes it possible to run the export as cronjob every 15 minutes.

This is a pure Docker version with a number of improvements over the
original. Since the original has gone unmaintained for 4 years, this is
the right fork to consider using.

## Setup with docker

-  Clone git repository
-  `cp data/config.php.dist data/config.php`
-  Adjust `data/config.php`
-  run docker-compose run build build
-  run docker-compose up -d
-  Setup cron to run the export every 15 minutes.

## Additional configuration

### Export some projects only

If you care about only a fraction of the projects in a JIRA instance,
you can choose to export those only.

Simply adjust `$allowedProjectKeys` in your configuration file:

```php
    $allowedProjectKeys = array('FOO', 'BAR');
```

If you want to exclude specific projects from the export, it is also
possible.

Set the keys you want to exclude in `$bannedProjectKeys`

```php
    $bannedProjectKeys = array('BAZ', 'SPAM');
```

### Run with a different config file

Use the `-c` command line option:

```sh
docker-compose start app -c data/config-another.customer.php
```

## Dependencies

-   Docker (tested with version 20.10.21) & Docker-Compose (tested with version 1.29.2)
-   Atlassian JIRA, at least version 4.4 with activated REST API.
    Version 5.1 or higher recommended. Works fine with Jira Cloud (you need to set the URL to the top-level API, not `/jira/`)

## About get-out-of-jira and jira-export

### License

- `get-out-of-jira` is a fork of `jira-export`, licensed under the [AGPL v3](https://www.gnu.org/licenses/agpl-3.0.en.html) license
- `jira-export` is licensed under the [AGPL v3](mailto:christian.weiske@netresearch.de) or later.

### Current author and maintainer

[Matcha](https://matchaxnb.github.io), [mail me](mailto:removethismatchaxnbremovethis@protonmail.com).

### Original Author

[Christian Weiske](http://www.netresearch.de/), [Netresearch GmbH & Co
KG](https://github.com/janl/gigan)

