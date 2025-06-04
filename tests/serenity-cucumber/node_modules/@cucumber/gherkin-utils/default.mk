SHELL := /usr/bin/env bash
# https://stackoverflow.com/questions/2483182/recursive-wildcards-in-gnu-make
rwildcard=$(foreach d,$(wildcard $(1:=/*)),$(call rwildcard,$d,$2) $(filter $(subst *,%,$2),$d))
TYPESCRIPT_SOURCE_FILES = $(sort $(call rwildcard,src test,*.ts *.tsx))
PRIVATE = $(shell node -e "console.log(require('./package.json').private)")
IS_TESTDATA = $(findstring -testdata,${CURDIR})
NPM_MODULE = $(shell cat package.json | jq .name --raw-output)

default: .tested
.PHONY: default

node_modules package-lock.json: package.json
	npm install

.codegen:
	touch $@

.tested: .tested-npm .built

.built: $(TYPESCRIPT_SOURCE_FILES) node_modules .codegen
	npm run build && \
	touch $@

.tested-npm: $(TYPESCRIPT_SOURCE_FILES) node_modules .codegen
	npm run test
	touch $@

pre-release: clean update-version update-dependencies default
.PHONY: pre-release

update-dependencies: node_modules 
	npm run upgrade -- --reject hast-util-sanitize,@types/node,react-markdown,rehype-raw,rehype-sanitize,remark-gfm
.PHONY: update-dependencies

update-version:
ifeq ($(IS_TESTDATA),-testdata)
	# no-op
else
ifdef NEW_VERSION
	npm --no-git-tag-version --allow-same-version version "$(NEW_VERSION)"
	# Update all npm packages that depend on us
	pushd ../.. && \
		./scripts/npm-each update_npm_dependency_if_exists package.json "$(NPM_MODULE)" "$(NEW_VERSION)"
else
	@echo -e "\033[0;31mNEW_VERSION is not defined. Can't update version :-(\033[0m"
	exit 1
endif
endif
.PHONY: update-version

publish: .codegen
ifeq ($(IS_TESTDATA),-testdata)
	# no-op
else
ifneq (true,$(PRIVATE))
	npm publish --access public
else
	@echo "Not publishing private npm module"
endif
endif
.PHONY: publish

post-release:
.PHONY: post-release

clean: clean-javascript
.PHONY: clean

clean-javascript:
	rm -rf .deps .codegen .tested* coverage dist acceptance
.PHONY: clean-javascript

clobber: clean
	rm -rf node_modules
.PHONY: clobber

### COMMON stuff for all platforms

BERP_VERSION = 1.3.0
BERP_GRAMMAR = gherkin.berp

define berp-generate-parser =
-! dotnet tool list --tool-path /usr/bin | grep "berp\s*$(BERP_VERSION)" && dotnet tool update Berp --version $(BERP_VERSION) --tool-path /usr/bin
berp -g $(BERP_GRAMMAR) -t $< -o $@ --noBOM
endef
