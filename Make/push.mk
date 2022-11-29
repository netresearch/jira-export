.PHONY: push stable latest

push:
	docker push ${REGISTRY}/${PROJECT}

stable:
	docker tag ${REGISTRY}/${PROJECT}:`cat VERSION` ${REGISTRY}/${PROJECT}:stable
