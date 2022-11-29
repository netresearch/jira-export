FROM php:7-alpine

RUN set -ex \
 && echo "http://mirror1.hs-esslingen.de/pub/Mirrors/alpine/latest-stable/main" > /etc/apk/repositories \
 && apk update && apk add unzip \
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

VOLUME ["/opt/jira-export/www/"]

CMD ["/opt/jira-export/bin/export-html.php"]
