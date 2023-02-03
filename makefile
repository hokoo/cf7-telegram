setup.all:
	bash ./install/setup.sh

setup.env:
	bash ./install/setup-env.sh

sync:
	bash ./install/sync.sh $(filter-out $@,$(MAKECMDGOALS))

clear.all:
	bash ./install/clear.sh

