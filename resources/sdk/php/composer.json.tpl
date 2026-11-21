{
    "name": "uipe/sdk",
    "description": "PHP SDK for the UIPE Developer API",
    "version": "{{SDK_VERSION}}",
    "type": "library",
    "license": "proprietary",
    "require": {
        "php": ">=8.0",
        "ext-curl": "*",
        "ext-json": "*"
    },
    "autoload": {
        "psr-4": { "Uipe\\": "src/" }
    },
    "scripts": {
        "test": "php tests/run.php"
    }
}
