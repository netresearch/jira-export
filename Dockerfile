FROM php:8-alpine

RUN set -ex \
 && echo "http://mirror1.hs-esslingen.de/pub/Mirrors/alpine/latest-stable/main" > /etc/apk/repositories \
 && apk update \
 && apk upgrade --available \
# Clean up anything else
 && rm -rf \
    /tmp/* \
    /var/tmp/* \
    /var/cache/apk/*

ADD bin/ /opt/jira-export/bin/
ADD data/ /opt/jira-export/data/
ADD vendor/ /opt/jira-export/vendor/
ADD www/.htaccess /opt/jira-export/www/.htaccess

# export-html.php writes only to $export_dir (www/), so it runs unprivileged.
# The chown has to come before VOLUME: later changes to that path are dropped.
RUN chown www-data:www-data /opt/jira-export/www

VOLUME ["/opt/jira-export/www/"]

USER www-data

CMD ["/opt/jira-export/bin/export-html.php"]