FROM php:8-alpine

#
# composer
#
# https://getcomposer.org/download/
# https://getcomposer.org/doc/faqs/how-to-install-composer-programmatically.md
#
ADD install-composer.sh /install-composer.sh
RUN chmod ugo+x /install-composer.sh
RUN /install-composer.sh \
 && mv composer.phar /usr/bin/composer \
 && rm /install-composer.sh

ADD build /usr/bin/build
RUN chmod ugo+x /usr/bin/build
ADD INFO /INFO

ENTRYPOINT []
CMD ["cat", "/INFO"]
