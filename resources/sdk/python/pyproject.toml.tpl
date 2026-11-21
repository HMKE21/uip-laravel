[build-system]
requires = ["setuptools>=61"]
build-backend = "setuptools.build_meta"

[project]
name = "uipe"
version = "{{SDK_VERSION}}"
description = "Python SDK for the UIPE Developer API"
readme = "README.md"
requires-python = ">=3.8"
dependencies = []

[tool.setuptools]
packages = ["uipe"]
