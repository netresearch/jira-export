MAJOR := $(shell awk -F. '{print $$1}' VERSION)
MAJOR := $(shell if [ -z "${MAJOR}" ]; then echo 0; else echo ${MAJOR}; fi;)
MINOR := $(shell awk -F. '{print $$2}' VERSION)
MINOR := $(shell if [ -z "${MINOR}" ]; then echo 0; else echo ${MINOR}; fi;)
FIX := $(shell awk -F. '{print $$3}' VERSION)
FIX := $(shell if [ -z "${FIX}" ]; then echo 0; else echo ${FIX}; fi;)

.PHONY: version_major _version_major version_minor _version_minor version_fix _version_fix echo_version commit_version

version_major: _version_major echo_version commit_version

_version_major:
	echo $$(( ${MAJOR} + 1 )).0.0 > VERSION

version_minor: _version_minor echo_version commit_version

_version_minor:
	echo ${MAJOR}.$$(( ${MINOR} + 1 )).0 > VERSION

version_fix: _version_fix echo_version commit_version

_version_fix:
	echo ${MAJOR}.${MINOR}.$$(( ${FIX} + 1 )) > VERSION

echo_version:
	echo New version: `cat VERSION`

commit_version:
	git add VERSION
	git commit -nm"Version `cat VERSION`"
	git tag v`cat VERSION`
	docker tag ${REGISTRY}/${PROJECT}:latest ${REGISTRY}/${PROJECT}:`cat VERSION`
