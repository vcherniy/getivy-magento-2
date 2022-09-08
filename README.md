# magento-plugin

Here you can find the source code Magento 2 Plugin of [Ivy](https://getivy.de): sustainability-driven payments. A more detailed integration guide is located [here](https://getivy.gitbook.io/integrate-us/).

### Compatibility

Magento 2.4.0 and higher

### Installation

You can install Ivy simply with composer. [Here](https://packagist.org/packages/getivy/magento-2) you can find the package.

```bash
composer require getivy/magento-2
php bin/magento setup:upgrade
php bin/magento module:enable Esparksinc_IvyPayment
php bin/magento setup:di:compile
```
