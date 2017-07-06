.PHONY: all build nc disable_cache dev dev-nc enable-dev clean-dev

all: build
	make show-images
	echo "=== DONE ==="
	echo "if build succeeded you should now commit your changes, run make version_minor|major and finally make push"

include Make/push.mk
include Make/version.mk

show-images:
	docker images ${REGISTRY}/${PROJECT}

nc: disable_cache build

disable_cache:
	$(eval DOCKER_BUILD_OPTION = --no-cache=true)

build:
	docker build ${DOCKER_BUILD_OPTION} -t ${REGISTRY}/${PROJECT}:latest .

dev: enable-dev build clean-dev

dev-nc: enable-dev disable_cache build

enable-dev:
	cp Dockerfile Dockerfile.dev
	perl -i~ -0777pe's{#[^\n]*\n}{}g;' Dockerfile.dev
	perl -i~ -0777pe's{ \\\n *&& }{\nRUN }g;' Dockerfile.dev
	perl -i~ -0777pe's{\nRUN cd }{\nWORKDIR }g;' Dockerfile.dev
	$(eval DOCKER_BUILD_OPTION = -f Dockerfile.dev)

clean-dev:
	rm Dockerfile.dev
