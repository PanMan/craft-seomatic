<?php
/**
 * SEOmatic plugin for Craft CMS 3.x
 *
 * A turnkey SEO implementation for Craft CMS that is comprehensive, powerful,
 * and flexible
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\seomatic\twigextensions;

use nystudio107\seomatic\Seomatic;
use nystudio107\seomatic\models\MetaJsonLd;

use Craft;

/**
 * @author    nystudio107
 * @package   Seomatic
 * @since     3.0.0
 */
class SeomaticTwigExtension extends \Twig_Extension implements \Twig_Extension_GlobalsInterface
{

    /**
     * @inheritdoc
     */
    public function getGlobals(): array
    {
        $result = [];

        $view = Craft::$app->getView();
        if ($view->getIsRenderingPageTemplate()) {
            // @todo: this is a hack to get it to work with Craft 3 beta 12
            $siteId = Craft::$app->getSites()->currentSite->id;
            if (!Seomatic::$plugin->metaBundles->getGlobalMetaBundle($siteId)) {
                Seomatic::$plugin->metaBundles->createGlobalMetaBundles();
                Seomatic::$plugin->metaBundles->createContentMetaBundles();
            }
            // Load the meta containers for this page
            Seomatic::$plugin->metaContainers->loadMetaContainers();
        }

        return $result;
    }

    /**
     * Return our Twig Extension name
     * @return string [description]
     */
    public function getName()
    {
        return 'Seomatic';
    }

    /**
     * Return our Twig filters
     * @return array [description]
     */
    public function getFilters()
    {
        return array(
            new \Twig_SimpleFilter('createJsonLd', [$this, 'createJsonLd']),
        );
    } /* -- getFilters */

    /**
     * Return our Twig functions
     * @return array [description]
     */
    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction('createJsonLd', [$this, 'createJsonLd']),
        );
    } /* -- getFunctions */

    /**
     * Create a new JSON-LD schema type object
     *
     * @param  string $jsonLdType The schema.org type to create
     * @param  array  $config     The default attributes for the model
     *
     * @return mixed              The model object
     */
    public function createJsonLd($jsonLdType, $config = [])
    {
        return $someSchema = MetaJsonLd::create($jsonLdType, $config);
    }

}