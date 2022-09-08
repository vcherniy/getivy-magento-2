# magento-plugin

Here you can find the source code Magento 2 Plugin of [Ivy](https://getivy.de): sustainability-driven payments. A more detailed integration guide is located [here](https://getivy.gitbook.io/integrate-us/).

To install the Plugin please download the Esparksinc_IvyPayment folder you can find in this repo. Then execute the following commands:

```bash
composer require getivy/magento-plugin
php bin/magento setup:upgrade
php bin/magento module:enable Esparksinc_IvyPayment
php bin/magento setup:di:compile
```
