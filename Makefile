default: build

prune:
	@docker volume prune

down:
	@docker compose down -v
up:
	@docker compose up --build -d 
	
build: down up

logs:
	@docker compose logs appwrite

dblogs:
	@docker compose logs appwrite-worker-databases

test:
	@docker compose exec appwrite test

dbtest:
	@docker compose exec appwrite test /usr/src/code/tests/e2e/Services/Databases/DatabasesCustomClientTest.php

testall:
	@docker compose exec appwrite test
