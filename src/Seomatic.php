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

namespace nystudio107\seomatic;

use nystudio107\seomatic\assetbundles\seomatic\SeomaticAsset;
use nystudio107\seomatic\helpers\MetaValue as MetaValueHelper;
use nystudio107\seomatic\models\Settings;
use nystudio107\seomatic\services\FrontendTemplates as FrontendTemplatesService;
use nystudio107\seomatic\services\Helper as HelperService;
use nystudio107\seomatic\services\JsonLd as JsonLdService;
use nystudio107\seomatic\services\Link as LinkService;
use nystudio107\seomatic\services\MetaBundles as MetaBundlesService;
use nystudio107\seomatic\services\MetaContainers as MetaContainersService;
use nystudio107\seomatic\services\Redirects as RedirectsService;
use nystudio107\seomatic\services\Script as ScriptService;
use nystudio107\seomatic\services\Sitemaps as SitemapsService;
use nystudio107\seomatic\services\Tag as TagService;
use nystudio107\seomatic\services\Template as TemplateService;
use nystudio107\seomatic\services\Title as TitleService;
use nystudio107\seomatic\twigextensions\SeomaticTwigExtension;
use nystudio107\seomatic\variables\SeomaticVariable;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\elements\Category;
use craft\events\CategoryGroupEvent;
use craft\events\ElementEvent;
use craft\events\ExceptionEvent;
use craft\events\PluginEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\SectionEvent;
use craft\services\Categories;
use craft\services\Elements;
use craft\services\Plugins;
use craft\services\Sections;
use craft\services\UserPermissions;
use craft\helpers\UrlHelper;
use craft\utilities\ClearCaches;
use craft\web\ErrorHandler;
use yii\web\HttpException;
use craft\web\UrlManager;
use craft\web\View;

use yii\base\Event;

/**
 * Class Seomatic
 *
 * @author    nystudio107
 * @package   Seomatic
 * @since     3.0.0
 *
 * @property  FrontendTemplatesService frontendTemplates
 * @property  HelperService            helper
 * @property  JsonLdService            jsonLd
 * @property  LinkService              link
 * @property  MetaBundlesService       metaBundles
 * @property  MetaContainersService    metaContainers
 * @property  RedirectsService         redirects
 * @property  ScriptService            script
 * @property  SitemapsService          sitemaps
 * @property  TagService               tag
 * @property  TemplateService          template
 * @property  TitleService             title
 */
class Seomatic extends Plugin
{
    // Constants
    // =========================================================================

    const SEOMATIC_HANDLE = 'Seomatic';

    // Static Properties
    // =========================================================================

    /**
     * @var Seomatic
     */
    public static $plugin;

    /**
     * @var SeomaticVariable
     */
    public static $seomaticVariable;

    /**
     * @var Settings
     */
    public static $settings;

    /**
     * @var ElementInterface
     */
    public static $matchedElement;

    /**
     * @var bool
     */
    public static $devMode;

    /**
     * @var View
     */
    public static $view;

    /**
     * @var
     */
    public static $language;

    /**
     * @var bool
     */
    public static $previewingMetaContainers = false;

    // Static Methods
    // =========================================================================

    /**
     * Set the matched element
     *
     * @param $element null|ElementInterface
     */
    public static function setMatchedElement($element)
    {
        self::$matchedElement = $element;
        /** @var  $element Element */
        if ($element) {
            self::$language = MetaValueHelper::getSiteLanguage($element->siteId);
        } else {
            self::$language = MetaValueHelper::getSiteLanguage(0);
        }
        MetaValueHelper::cache();
    }

    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public $schemaVersion = '3.0.1';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;
        // Initialize properties
        self::$settings = Seomatic::$plugin->getSettings();
        self::$devMode = Craft::$app->getConfig()->getGeneral()->devMode;
        self::$view = Craft::$app->getView();
        MetaValueHelper::cache();
        // If devMode is on, always force the environment to be "local"
        if (self::$devMode) {
            self::$settings->environment = "local";
        }
        $this->name = Seomatic::$settings->pluginName;
        // Handler: EVENT_AFTER_INSTALL_PLUGIN
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                    // Invalidate our caches after we've been installed
                    $this->clearAllCaches();
                    // Send them to our welcome screen
                    $request = Craft::$app->getRequest();
                    if ($request->isCpRequest) {
                        Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('seomatic/welcome'))->send();
                    }
                }
            }
        );

        // We're loaded
        Craft::info(
            Craft::t(
                'seomatic',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
        // Add in our event listeners that are needed for every request
        $this->installGlobalEventListeners();
        // Only respond to non-console site requests
        $request = Craft::$app->getRequest();
        if ($request->getIsSiteRequest() && !$request->getIsConsoleRequest()) {
            $this->handleSiteRequest();
        }
        // AdminCP magic
        if ($request->getIsCpRequest() && !$request->getIsConsoleRequest()) {
            $this->handleAdminCpRequest();
        }
    }

    /**
     * @inheritdoc
     */
    public function getSettingsResponse()
    {
        return Craft::$app->runAction('seomatic/settings/plugin');
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem()
    {
        $subNavs = [];
        $navItem = parent::getCpNavItem();
        $currentUser = Craft::$app->getUser()->getIdentity();
        // Only show sub-navs the user has permission to view
        if ($currentUser->can('seomatic:global-meta')) {
            $subNavs['global'] = [
                'label' => 'Global Meta',
                'url'   => 'seomatic/global',
            ];
        }
        if ($currentUser->can('seomatic:content-meta')) {
            $subNavs['content'] = [
                'label' => 'Content Meta',
                'url'   => 'seomatic/content',
            ];
        }
        if ($currentUser->can('seomatic:site-settings')) {
            $subNavs['site'] = [
                'label' => 'Site Settings',
                'url'   => 'seomatic/site',
            ];
        }
        if ($currentUser->can('seomatic:plugin-settings')) {
            $subNavs['plugin'] = [
                'label' => 'Plugin Settings',
                'url'   => 'seomatic/plugin',
            ];
        }
        $navItem = array_merge($navItem, [
            'subnav' => $subNavs,
        ]);

        return $navItem;
    }

    /**
     * Clear all the caches!
     */
    public function clearAllCaches()
    {
        Seomatic::$plugin->frontendTemplates->invalidateCaches();
        Seomatic::$plugin->metaContainers->invalidateCaches();
        Seomatic::$plugin->sitemaps->invalidateCaches();
    }

    // Protected Methods
    // =========================================================================

    /**
     * Install global event listeners
     */
    protected function installGlobalEventListeners()
    {
        // Handler: Sections::EVENT_AFTER_SAVE_SECTION
        Event::on(
            Sections::class,
            Sections::EVENT_AFTER_SAVE_SECTION,
            function (SectionEvent $event) {
                Craft::debug(
                    'Sections::EVENT_AFTER_SAVE_SECTION',
                    __METHOD__
                );
                Seomatic::$plugin->metaBundles->invalidateMetaBundleById(
                    MetaBundlesService::SECTION_META_BUNDLE,
                    $event->section->id,
                    $event->isNew
                );
                // Create the meta bundles for this section if it's new
                if ($event->isNew) {
                    Seomatic::$plugin->metaBundles->createContentMetaBundleForSection($event->section);
                    Seomatic::$plugin->sitemaps->submitSitemapIndex();
                }
            }
        );
        // Handler: Sections::EVENT_AFTER_DELETE_SECTION
        Event::on(
            Sections::class,
            Sections::EVENT_AFTER_DELETE_SECTION,
            function (SectionEvent $event) {
                Craft::debug(
                    'Sections::EVENT_AFTER_DELETE_SECTION',
                    __METHOD__
                );
                Seomatic::$plugin->metaBundles->invalidateMetaBundleById(
                    MetaBundlesService::SECTION_META_BUNDLE,
                    $event->section->id,
                    false
                );
                // Delete the meta bundles for this section
                Seomatic::$plugin->metaBundles->deleteMetaBundleBySourceId(
                    MetaBundlesService::SECTION_META_BUNDLE,
                    $event->section->id
                );
            }
        );
        // Handler: Categories::EVENT_AFTER_SAVE_GROUP
        Event::on(
            Categories::class,
            Categories::EVENT_AFTER_SAVE_GROUP,
            function (CategoryGroupEvent $event) {
                Craft::debug(
                    'Categories::EVENT_AFTER_SAVE_GROUP',
                    __METHOD__
                );
                Seomatic::$plugin->metaBundles->invalidateMetaBundleById(
                    MetaBundlesService::CATEGORYGROUP_META_BUNDLE,
                    $event->categoryGroup->id,
                    $event->isNew
                );
                // Create the meta bundles for this category if it's new
                if ($event->isNew) {
                    Seomatic::$plugin->metaBundles->createContentMetaBundleForCategoryGroup($event->categoryGroup);
                    Seomatic::$plugin->sitemaps->submitSitemapIndex();
                }
            }
        );
        // Handler: Categories::EVENT_AFTER_DELETE_GROUP
        Event::on(
            Categories::class,
            Categories::EVENT_AFTER_DELETE_GROUP,
            function (CategoryGroupEvent $event) {
                Craft::debug(
                    'Categories::EVENT_AFTER_DELETE_GROUP',
                    __METHOD__
                );
                Seomatic::$plugin->metaBundles->invalidateMetaBundleById(
                    MetaBundlesService::CATEGORYGROUP_META_BUNDLE,
                    $event->categoryGroup->id,
                    false
                );
                // Delete the meta bundles for this category
                Seomatic::$plugin->metaBundles->deleteMetaBundleBySourceId(
                    MetaBundlesService::CATEGORYGROUP_META_BUNDLE,
                    $event->categoryGroup->id
                );
            }
        );
        // Handler: Elements::EVENT_AFTER_SAVE_ELEMENT
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            function (ElementEvent $event) {
                Craft::debug(
                    'Elements::EVENT_AFTER_SAVE_ELEMENT',
                    __METHOD__
                );
                /** @var  $element Element */
                $element = $event->element;
                Seomatic::$plugin->metaBundles->invalidateMetaBundleByElement(
                    $element,
                    $event->isNew
                );
                if ($event->isNew) {
                    Seomatic::$plugin->sitemaps->submitSitemapForElement($element);
                }
            }
        );
        // Handler: Elements::EVENT_AFTER_DELETE_ELEMENT
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            function (ElementEvent $event) {
                Craft::debug(
                    'Elements::EVENT_AFTER_DELETE_ELEMENT',
                    __METHOD__
                );
                /** @var  $element Element */
                $element = $event->element;
                Seomatic::$plugin->metaBundles->invalidateMetaBundleByElement(
                    $element,
                    false
                );
            }
        );
        // Handler: Plugins::EVENT_AFTER_INSTALL_PLUGIN
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                Craft::debug(
                    'Plugins::EVENT_AFTER_INSTALL_PLUGIN',
                    __METHOD__
                );
                if ($event->plugin === $this) {
                    //This is our plugin that's been installed
                }
            }
        );
    }

    /**
     * Handle site requests
     */
    protected function handleSiteRequest()
    {
        // Add in our Twig extensions
        Seomatic::$view->registerTwigExtension(new SeomaticTwigExtension);
        // Load the sitemap containers
        Seomatic::$plugin->sitemaps->loadSitemapContainers();
        // Load the frontend template containers
        Seomatic::$plugin->frontendTemplates->loadFrontendTemplateContainers();
        // Handler: ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION
        Event::on(
            ErrorHandler::class,
            ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION,
            function (ExceptionEvent $event) {
                Craft::debug(
                    'ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION',
                    __METHOD__
                );
                $exception = $event->exception;
                // If this is a Twig Runtime exception, use the previous one instead
                if ($exception instanceof \Twig_Error_Runtime &&
                    ($previousException = $exception->getPrevious()) !== null) {
                    $exception = $previousException;
                }
                // If this is a 404 error, see if we can handle it
                if ($exception instanceof HttpException && $exception->statusCode === 404) {
                    Seomatic::$plugin->redirects->handle404();
                }
            }
        );
        // Handler: View::EVENT_END_PAGE
        Event::on(
            View::class,
            View::EVENT_END_PAGE,
            function (Event $event) {
                Craft::debug(
                    'View::EVENT_END_PAGE',
                    __METHOD__
                );
                // The page is done rendering, include our meta containers
                if (Seomatic::$settings->renderEnabled && Seomatic::$seomaticVariable) {
                    Seomatic::$plugin->metaContainers->includeMetaContainers();
                }
            }
        );
    }

    /**
     * Handle AdminCP requests
     */
    protected function handleAdminCpRequest()
    {
        // Add in our Twig extensions
        Seomatic::$view->registerTwigExtension(new SeomaticTwigExtension);
        // Handler: UrlManager::EVENT_REGISTER_CP_URL_RULES
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                Craft::debug(
                    'UrlManager::EVENT_REGISTER_CP_URL_RULES',
                    __METHOD__
                );
                // Register our AdminCP routes
                $event->rules = array_merge(
                    $event->rules,
                    $this->customAdminCpRoutes()
                );
            }
        );
        // Handler: UserPermissions::EVENT_REGISTER_PERMISSIONS
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                Craft::debug(
                    'UserPermissions::EVENT_REGISTER_PERMISSIONS',
                    __METHOD__
                );
                // Register our custmo permissions
                $event->permissions[Craft::t('seomatic', 'SEOmatic')] = $this->customAdminCpPermissions();
            }
        );
        // Handler: ClearCaches::EVENT_REGISTER_CACHE_OPTIONS
        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            function (RegisterCacheOptionsEvent $event) {
                Craft::debug(
                    'ClearCaches::EVENT_REGISTER_CACHE_OPTIONS',
                    __METHOD__
                );
                // Register our AdminCP routes
                $event->options = array_merge(
                    $event->options,
                    $this->customAdminCpCacheOptions()
                );
            }
        );
        // Entries sidebar
        self::$view->hook('cp.entries.edit.details', function (&$context) {
            $html = '';
            self::$view->registerAssetBundle(SeomaticAsset::class);
            /** @var  $entry Entry */
            $entry = $context['entry'];
            if (!empty($entry) && !empty($entry->uri)) {
                Seomatic::$plugin->metaContainers->previewMetaContainers($entry->uri, $entry->siteId, true);
                // Render our sidebar template
                $html = Craft::$app->view->renderTemplate(
                    'seomatic/_sidebars/entry.twig'
                );
            }

            return $html;
        });
        // Category Groups sidebar
        self::$view->hook('cp.categories.edit.details', function (&$context) {
            $html = '';
            self::$view->registerAssetBundle(SeomaticAsset::class);
            /** @var  $category Category */
            $category = $context['category'];
            if (!empty($category) && !empty($category->uri)) {
                Seomatic::$plugin->metaContainers->previewMetaContainers($category->uri, $category->siteId, true);
                // Render our sidebar template
                $html = Craft::$app->view->renderTemplate(
                    'seomatic/_sidebars/category.twig'
                );
            }

            return $html;
        });
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel()
    {
        return new Settings();
    }

    /**
     * Return the custom AdminCP routes
     *
     * @return array
     */
    protected function customAdminCpRoutes(): array
    {
        return [
            'seomatic' =>
                'seomatic/settings/content',
            'seomatic/global' =>
                'seomatic/settings/global',
            'seomatic/global/<siteHandle:{handle}>' =>
                'seomatic/settings/global',
            'seomatic/content' =>
                'seomatic/settings/content',
            'seomatic/content/<siteHandle:{handle}>' =>
                'seomatic/settings/content',
            'seomatic/edit-content/<sourceBundleType:{handle}>/<sourceHandle:{handle}>' =>
                'seomatic/settings/edit-content',
            'seomatic/edit-content/<sourceBundleType:{handle}>/<sourceHandle:{handle}>/<siteHandle:{handle}>' =>
                'seomatic/settings/edit-content',
            'seomatic/site' =>
                'seomatic/settings/site',
            'seomatic/site/<siteHandle:{handle}>' =>
                'seomatic/settings/site',
            'seomatic/plugin' =>
                'seomatic/settings/plugin',
        ];
    }

    /**
     * Returns the custom AdminCP cache options.
     *
     * @return array
     */
    protected function customAdminCpCacheOptions(): array
    {
        return [
            // Frontend template caches
            [
                'key'    => 'seomatic-frontendtemplate-caches',
                'label'  => Craft::t('seomatic', 'SEOmatic frontend template caches'),
                'action' =>  [Seomatic::$plugin->frontendTemplates, 'invalidateCaches'],
            ],
            // Meta bundle caches
            [
                'key'    => 'seomatic-metabundle-caches',
                'label'  => Craft::t('seomatic', 'SEOmatic metadata caches'),
                'action' =>  [Seomatic::$plugin->metaContainers, 'invalidateCaches'],
            ],
            // Sitemap caches
            [
                'key'    => 'seomatic-sitemap-caches',
                'label'  => Craft::t('seomatic', 'SEOmatic sitemap caches'),
                'action' =>  [Seomatic::$plugin->sitemaps, 'invalidateCaches'],
            ]
        ];
    }
    /**
     * Returns the custom AdminCP user permissions.
     *
     * @return array
     */
    protected function customAdminCpPermissions(): array
    {
        return [
            "seomatic:global-meta" => [
                'label' => Craft::t('seomatic', 'Edit Global Meta'),
                'nested' => [
                    "seomatic:global-meta:general" => [
                        'label' => Craft::t('seomatic', 'General'),
                    ],
                    "seomatic:global-meta:twitter" => [
                        'label' => Craft::t('seomatic', 'Twitter'),
                    ],
                    "seomatic:global-meta:facebook" => [
                        'label' => Craft::t('seomatic', 'Facebook'),
                    ],
                    "seomatic:global-meta:robots" => [
                        'label' => Craft::t('seomatic', 'Robots'),
                    ],
                    "seomatic:global-meta:humans" => [
                        'label' => Craft::t('seomatic', 'Humans'),
                    ],
                ]
            ],
            "seomatic:content-meta" => [
                'label' => Craft::t('seomatic', 'Edit Content Meta'),
                'nested' => [
                    "seomatic:content-meta:general" => [
                        'label' => Craft::t('seomatic', 'General'),
                    ],
                    "seomatic:content-meta:twitter" => [
                        'label' => Craft::t('seomatic', 'Twitter'),
                    ],
                    "seomatic:content-meta:facebook" => [
                        'label' => Craft::t('seomatic', 'Facebook'),
                    ],
                    "seomatic:content-meta:sitemap" => [
                        'label' => Craft::t('seomatic', 'Sitemap'),
                    ],
                ]
            ],
            "seomatic:site-settings" => [
                'label' => Craft::t('seomatic', 'Edit Site Settings'),
                'nested' => [
                    "seomatic:site-settings:identity" => [
                        'label' => Craft::t('seomatic', 'Identity'),
                    ],
                    "seomatic:site-settings:creator" => [
                        'label' => Craft::t('seomatic', 'Creator'),
                    ],
                    "seomatic:site-settings:social-media" => [
                        'label' => Craft::t('seomatic', 'Social Media'),
                    ],
                    "seomatic:site-settings:tracking" => [
                        'label' => Craft::t('seomatic', 'Tracking'),
                    ],
                ]
            ],
            "seomatic:plugin-settings" => [
                'label' => Craft::t('seomatic', 'Edit Plugin Settings'),
            ],
        ];
    }
}
