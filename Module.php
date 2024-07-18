<?php declare(strict_types=1);

namespace ContactUs;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Form\Element;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Form\Element as OmekaElement;
use Omeka\Module\AbstractModule;
use Omeka\Stdlib\Message;

/**
 * Contact Us
 *
 * @copyright Daniel Berthereau, 2018-2024
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    const STORE_PREFIX = 'contactus';

    const SETTINGS = [
        'contactus_notify_recipients' => [],
        'contactus_author' => 'disabled',
        'contactus_author_only' => false,
        'contactus_send_with_user_email' => false,
        'contactus_create_zip' => 'original',
        'contactus_delete_zip' => 30,
    ];

    const SITE_SETTINGS = [
        'contactus_notify_recipients' => [],
        'contactus_notify_subject' => '',
        'contactus_notify_body' => <<<'TEXT'
            A user has contacted you.

            email: {email}
            name: {name}
            ip: {ip}

            {newsletter}
            subject: {subject}
            message:

            {message}
            TEXT,
        'contactus_confirmation_enabled' => true,
        'contactus_confirmation_subject' => 'Confirmation contact',
        'contactus_confirmation_body' => <<<'TEXT'
            Hi {name},

            Thanks to contact us!

            We will answer you as soon as possible.

            Sincerely,

            {main_title}
            {main_url}

            --

            {newsletter}
            Your message:
            Subject: {subject}

            {message}
            TEXT,
        'contactus_confirmation_newsletter_subject' => 'Subscription to newsletter of {main_title}',
        'contactus_confirmation_newsletter_body' => <<<'TEXT'
            Hi,

            Thank you for subscribing to our newsletter.

            Sincerely,
            TEXT,
        'contactus_to_author_subject' => 'Message to the author',
        'contactus_to_author_body' => <<<'TEXT'
            Hi {user_name},

            The visitor {name} ({email} made the following request about a resource on {main_title}:

            Thanks to reply directly to the email above and do not use "reply".

            Sincerely,

            --

            From: {name} <{email}>
            Subject: {subject}

            {message}
            TEXT,
        'contactus_antispam' => true,
        'contactus_questions' => [
            'How many are zero plus 1 (in number)?' => '1',
            'How many are one plus 1 (in number)?' => '2',
            'How many are one plus 2 (in number)?' => '3',
            'How many are one plus 3 (in number)?' => '4',
            'How many are two plus 1 (in number)?' => '3',
            'How many are two plus 2 (in number)?' => '4',
            'How many are two plus 3 (in number)?' => '5',
            'How many are three plus 1 (in number)?' => '4',
            'How many are three plus 2 (in number)?' => '5',
            'How many are three plus 3 (in number)?' => '6',
        ],
        'contactus_append_resource_show' => [],
        'contactus_append_items_browse' => false,
    ];

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');

        // Since Omeka 1.4, modules are ordered, so Guest come after Selection.
        if (!$acl->hasRole('guest')) {
            $acl->addRole('guest');
        }
        if (!$acl->hasRole('guest_private')) {
            $acl->addRole('guest_private');
        }
        $roles = $acl->getRoles();
        $adminRoles = [
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
            \Omeka\Permissions\Acl::ROLE_EDITOR,
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
        ];
        $userRoles = array_diff($roles, $adminRoles);
        // A check is done on attached files for anonymous people and guests.
        $acl
            // Any user or anonymous people can create a message.
            ->allow(
                null,
                [
                    Entity\Message::class,
                    Api\Adapter\MessageAdapter::class,
                ],
                ['create']
            )
            // Users can read their own messages but cannot delete them once
            // sent.
            ->allow(
                $userRoles,
                [Entity\Message::class],
                ['read'],
                new \Omeka\Permissions\Assertion\OwnsEntityAssertion
            )
            // The search is limited to own messages directly inside adapter.
            ->allow(
                $userRoles,
                [Api\Adapter\MessageAdapter::class],
                ['read', 'search']
            )
            ->allow(
                null,
                ['ContactUs\Controller\Zip']
            );
        // Add possibility to list search own entities.
            // Admins can admin messages (browse, flag, delete, etc.).
            // This is automatic via acl factory.
    }

    public function install(ServiceLocatorInterface $services)
    {
        $connection = $services->get('Omeka\Connection');
        $settings = $services->get('Omeka\Settings');

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE `contact_message` (
                `id` INT AUTO_INCREMENT NOT NULL,
                `owner_id` INT DEFAULT NULL,
                `resource_id` INT DEFAULT NULL,
                `site_id` INT DEFAULT NULL,
                `email` VARCHAR(190) NOT NULL,
                `name` VARCHAR(190) DEFAULT NULL,
                `subject` LONGTEXT DEFAULT NULL,
                `body` LONGTEXT NOT NULL,
                `fields` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)',
                `source` LONGTEXT DEFAULT NULL,
                `media_type` VARCHAR(190) DEFAULT NULL,
                `storage_id` VARCHAR(190) DEFAULT NULL,
                `extension` VARCHAR(190) DEFAULT NULL,
                `request_url` VARCHAR(1024) DEFAULT NULL COLLATE `latin1_bin`,
                `ip` VARCHAR(45) NOT NULL,
                `user_agent` VARCHAR(1024) DEFAULT NULL,
                `newsletter` TINYINT(1) DEFAULT NULL,
                `is_read` TINYINT(1) DEFAULT '0' NOT NULL,
                `is_spam` TINYINT(1) DEFAULT '0' NOT NULL,
                `to_author` TINYINT(1) DEFAULT '0' NOT NULL,
                `created` DATETIME NOT NULL,
                `modified` DATETIME DEFAULT NULL,
                UNIQUE INDEX UNIQ_2C9211FE5CC5DB90 (`storage_id`),
                INDEX IDX_2C9211FE7E3C61F9 (`owner_id`),
                INDEX IDX_2C9211FE89329D25 (`resource_id`),
                INDEX IDX_2C9211FEF6BD1646 (`site_id`),
                PRIMARY KEY(`id`)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $connection->executeStatement(<<<'SQL'
            ALTER TABLE `contact_message`
            ADD CONSTRAINT FK_2C9211FE7E3C61F9 FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`)
                ON DELETE SET NULL
        SQL);

        $connection->executeStatement(<<<'SQL'
            ALTER TABLE `contact_message`
            ADD CONSTRAINT FK_2C9211FE89329D25 FOREIGN KEY (`resource_id`) REFERENCES `resource` (`id`)
                ON DELETE SET NULL
        SQL);

        $connection->executeStatement(<<<'SQL'
            ALTER TABLE `contact_message`
            ADD CONSTRAINT FK_2C9211FEF6BD1646 FOREIGN KEY (`site_id`) REFERENCES `site` (`id`)
                ON DELETE SET NULL;
        SQL);

        foreach (self::SETTINGS as $id => $default) {
            $settings->set($id, $default);
        }
    }

    public function uninstall(ServiceLocatorInterface $services)
    {
        $connection = $services->get('Omeka\Connection');
        $settings = $services->get('Omeka\Settings');
        $siteSettings = $services->get('Omeka\Settings\Site');
        $api = $services->get('Omeka\ApiManager');

        $connection->executeStatement('DROP TABLE IF EXISTS `contact_message`');

        foreach (array_keys(self::SETTINGS) as $id) {
            $settings->delete($id);
        }

        $sites = $api->search('sites')->getContent();
        foreach ($sites as $site) {
            foreach (array_keys(self::SITE_SETTINGS) as $id) {
                $siteSettings->delete($id, $site->id());
            }
        }

        if (!empty($_POST['remove-contact-us'])) {
            $config = $services->get('Config');
            $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
            $this->rmDir($basePath . '/' . self::STORE_PREFIX);
        }
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services)
    {
        $plugins = $services->get('ControllerPluginManager');
        $url = $services->get('ViewHelperManager')->get('url');
        $api = $plugins->get('api');
        $settings = $services->get('Omeka\Settings');
        $siteSettings = $services->get('Omeka\Settings\Site');
        $translate = $plugins->get('translate');
        $connection = $services->get('Omeka\Connection');
        $messenger = $plugins->get('messenger');
        $entityManager = $services->get('Omeka\EntityManager');

        if (version_compare($oldVersion, '3.3.8', '<')) {
            $settings->delete('contactus_html');
            $ids = $api->search('sites', [], ['initialize' => false, 'returnScalar' => 'id'])->getContent();
            foreach ($ids as $id) {
                $siteSettings->setTargetId($id);
                $siteSettings->delete('contactus_html');
            }
        }

        if (version_compare($oldVersion, '3.3.8.1', '<')) {
            $sqls = <<<'SQL'
                CREATE TABLE `contact_message` (
                    `id` INT AUTO_INCREMENT NOT NULL,
                    `owner_id` INT DEFAULT NULL,
                    `resource_id` INT DEFAULT NULL,
                    `site_id` INT DEFAULT NULL,
                    `email` VARCHAR(190) NOT NULL,
                    `name` VARCHAR(190) DEFAULT NULL,
                    `subject` LONGTEXT DEFAULT NULL,
                    `body` LONGTEXT NOT NULL,
                    `source` LONGTEXT DEFAULT NULL,
                    `media_type` VARCHAR(190) DEFAULT NULL,
                    `storage_id` VARCHAR(190) DEFAULT NULL,
                    `extension` VARCHAR(255) DEFAULT NULL,
                    `request_url` VARCHAR(1024) DEFAULT NULL,
                    `ip` VARCHAR(45) NOT NULL,
                    `user_agent` TEXT DEFAULT NULL,
                    `is_read` TINYINT(1) DEFAULT 0 NOT NULL,
                    `is_spam` TINYINT(1) DEFAULT 0 NOT NULL,
                    `newsletter` TINYINT(1) DEFAULT NULL,
                    `created` DATETIME NOT NULL,
                    UNIQUE INDEX UNIQ_2C9211FE5CC5DB90 (`storage_id`),
                    INDEX IDX_2C9211FE7E3C61F9 (`owner_id`),
                    INDEX IDX_2C9211FE89329D25 (`resource_id`),
                    INDEX IDX_2C9211FEF6BD1646 (`site_id`),
                    PRIMARY KEY(`id`)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
                ALTER TABLE `contact_message` ADD CONSTRAINT FK_2C9211FE7E3C61F9 FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
                ALTER TABLE `contact_message` ADD CONSTRAINT FK_2C9211FE89329D25 FOREIGN KEY (`resource_id`) REFERENCES `resource` (`id`) ON DELETE SET NULL;
                ALTER TABLE `contact_message` ADD CONSTRAINT FK_2C9211FEF6BD1646 FOREIGN KEY (`site_id`) REFERENCES `site` (`id`) ON DELETE SET NULL;
            SQL;
            try {
                foreach (explode(";\n", $sqls) as $sql) {
                    $connection->executeStatement($sql);
                }
            } catch (\Exception $e) {
                // Already installed.
            }
        }

        if (version_compare($oldVersion, '3.3.8.4', '<')) {
            $settings->delete('contactus_html');
            $ids = $api->search('sites', [], ['initialize' => false, 'returnScalar' => 'id'])->getContent();
            foreach ($ids as $id) {
                $siteSettings->setTargetId($id);
                $siteSettings->set('contactus_notify_body', self::SITE_SETTINGS['contactus_notify_body']);
                $siteSettings->set('contactus_notify_subject', $siteSettings->get('contactus_subject'));
                $siteSettings->delete('contactus_subject');
            }

            // Just to hide the data. Will be removed when the page will be resaved.
            $sql = <<<'SQL'
                UPDATE site_page_block
                SET data = REPLACE(data, '"notify_recipients":', '"_old_notify_recipients":')
                WHERE layout = "contactUs";
            SQL;
            $connection->executeStatement($sql);
        }

        if (version_compare($oldVersion, '3.3.8.5', '<')) {
            $messenger->addNotice('A checkbox for consent has been added to the user form. You may update the default label in site settings'); // @translate

            $ids = $api->search('sites', [], ['initialize' => false, 'returnScalar' => 'id'])->getContent();
            foreach ($ids as $id) {
                $siteSettings->setTargetId($id);
                $siteSettings->delete('contactus_newsletter');
                $siteSettings->delete('contactus_newsletter_label');
                $siteSettings->delete('contactus_attach_file');
                $siteSettings->set('contactus_consent_label', 'I allow the site owner to store my name and my email to answer to this message.');
            }

            $sql = <<<'SQL'
                UPDATE site_page_block
                SET
                    data = REPLACE(
                        data,
                        '"confirmation_enabled":',
                        '"consent_label":"I allow the site owner to store my name and my email to answer to this message.","confirmation_enabled":'
                    )
                WHERE layout = "contactUs";
            SQL;
            $connection->executeStatement($sql);
        }

        if (version_compare($oldVersion, '3.3.8.7', '<')) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `contact_message` DROP FOREIGN KEY FK_2C9211FE7E3C61F9
            SQL);

            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `contact_message`
                    CHANGE `owner_id` `owner_id` INT DEFAULT NULL,
                    CHANGE `resource_id` `resource_id` INT DEFAULT NULL,
                    CHANGE `site_id` `site_id` INT DEFAULT NULL,
                    CHANGE `name` `name` VARCHAR(190) DEFAULT NULL,
                    CHANGE `media_type` `media_type` VARCHAR(190) DEFAULT NULL,
                    CHANGE `storage_id` `storage_id` VARCHAR(190) DEFAULT NULL,
                    CHANGE `extension` `extension` VARCHAR(190) DEFAULT NULL,
                    CHANGE `request_url` `request_url` VARCHAR(1024) DEFAULT NULL COLLATE `latin1_bin`,
                    CHANGE `user_agent` `user_agent` VARCHAR(1024) DEFAULT NULL,
                    CHANGE `newsletter` `newsletter` TINYINT(1) DEFAULT NULL
            SQL);

            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `contact_message`
                ADD CONSTRAINT FK_2C9211FE7E3C61F9 FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`) ON DELETE SET NULL
            SQL);
        }

        if (version_compare($oldVersion, '3.3.8.8', '<')) {
            $sql = <<<'SQL'
                ALTER TABLE `contact_message`
                ADD `to_author` TINYINT(1) DEFAULT '0' NOT NULL AFTER `is_spam`,
                CHANGE `owner_id` `owner_id` INT DEFAULT NULL,
                CHANGE `resource_id` `resource_id` INT DEFAULT NULL,
                CHANGE `site_id` `site_id` INT DEFAULT NULL,
                CHANGE `name` `name` VARCHAR(190) DEFAULT NULL,
                CHANGE `media_type` `media_type` VARCHAR(190) DEFAULT NULL,
                CHANGE `storage_id` `storage_id` VARCHAR(190) DEFAULT NULL,
                CHANGE `extension` `extension` VARCHAR(190) DEFAULT NULL,
                CHANGE `request_url` `request_url` VARCHAR(1024) DEFAULT NULL COLLATE `latin1_bin`,
                CHANGE `user_agent` `user_agent` VARCHAR(1024) DEFAULT NULL,
                CHANGE `newsletter` `newsletter` TINYINT(1) DEFAULT NULL
            SQL;
            $connection->executeStatement($sql);

            $siteIds = $api->search('sites', [], ['initialize' => false, 'returnScalar' => 'id'])->getContent();
            foreach ($siteIds as $siteId) {
                $siteSettings->setTargetId($siteId);
                $siteSettings->set('contactus_to_author_subject', self::SITE_SETTINGS['contactus_to_author_subject']);
                $siteSettings->set('contactus_to_author_body', self::SITE_SETTINGS['contactus_to_author_body']);
            }

            $messenger->addSuccess('It’s now possible to set a specific message when contacting author.'); // @translate
            $messenger->addSuccess('It’s now possible to contact authors of a resource via the view helper contactUs().'); // @translate
        }

        if (version_compare($oldVersion, '3.3.8.11', '<')) {
            $sql = <<<'SQL'
                UPDATE `contact_message`
                SET `resource_id` = SUBSTRING_INDEX(`request_url`, '/', -1)
                WHERE `resource_id` IS NULL
                    AND `request_url` IS NOT NULL
                    AND SUBSTRING_INDEX(`request_url`, '/', -1) REGEXP '^[0-9]+$'
            SQL;
            $connection->executeStatement($sql);
        }

        if (version_compare($oldVersion, '3.4.8.13', '<')) {
            $sql = <<<'SQL'
                ALTER TABLE `contact_message`
                ADD `fields` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)' AFTER `body`
            SQL;
            $connection->executeStatement($sql);

            $messenger->addSuccess('It’s now possible to append specific fields to the form.'); // @translate
            $messenger->addSuccess('It’s now possible to add a contact form in item/show for themes supporting resource blocks.'); // @translate
        }

        if (version_compare($oldVersion, '3.4.10', '<')) {
            $messenger->addSuccess('It’s now possible to add a contact form in item/browse and to send a list of resource ids (need a line in theme).'); // @translate
        }

        if (version_compare($oldVersion, '3.4.11', '<')) {
            $sql = <<<'SQL'
                ALTER TABLE `contact_message`
                ADD `modified` DATETIME DEFAULT NULL AFTER `created`
            SQL;
            $connection->executeStatement($sql);

            // Set modified for all old messages.
            $sql = <<<'SQL'
                UPDATE `contact_message`
                SET `modified` = `created`
                WHERE `is_read` IS NOT NULL
                    OR `is_spam` IS NOT NULL
            SQL;
            $connection->executeStatement($sql);

            $settings->set('contactus_create_zip', $settings->get('contactus_zip') ?: '');
            $settings->delete('contactus_zip');
            $settings->set('contactus_delete_zip', 30);

            $message = new Message(
                'It’s now possible to prepare a zip file of asked files to send to a visitor via a link. See %ssettings%s.', // @translate
                sprintf('<a href="%s">', $url('admin/default', ['controller' => 'setting'], ['fragment' => 'contact'])),
                '</a>',
            );
            $message->setEscapeHtml(false);
            $messenger->addSuccess($message);
        }

        if (version_compare($oldVersion, '3.4.13', '<')) {
            $settings->set('contactus_create_zip', $settings->get('contactus_create_zip', 'original') ?: 'original');
            $messenger->addSuccess('A new button allows to create a zip for any contact.'); // @translate
        }

        if (version_compare($oldVersion, '3.4.14', '<')) {
            $ids = $api->search('sites', [], ['initialize' => false, 'returnScalar' => 'id'])->getContent();
            foreach ($ids as $id) {
                $siteSettings->setTargetId($id);
                $siteSettings->set('contactus_append_resource_show', self::SITE_SETTINGS['contactus_append_resource_show']);
                $siteSettings->set('contactus_append_items_browse', self::SITE_SETTINGS['contactus_append_items_browse']);
            }

            $messenger->addWarning('Two new options allow to append the contact form to resource pages. They are disabled by default, so check them if you need them.'); // @translate
            $messenger->addSuccess('A new option allows to use the user email to send message. It is not recommended because many emails providers reject them as spam. Use it only if you manage your own domain.'); // @translate
        }

        if (version_compare($oldVersion, '3.4.15', '<')) {
            $ids = $api->search('sites', [], ['initialize' => false, 'returnScalar' => 'id'])->getContent();
            foreach ($ids as $id) {
                $siteSettings->setTargetId($id);
                $siteSettings->set('contactus_confirmation_newsletter_subject', self::SITE_SETTINGS['contactus_confirmation_newsletter_subject']);
                $siteSettings->set('contactus_confirmation_newsletter_body', self::SITE_SETTINGS['contactus_confirmation_newsletter_body']);
            }

            $messenger->addSuccess('A new block allows to display a form to subscribe to a newsletter.'); // @translate
        }
    }

    public function warnUninstall(Event $event): void
    {
        $view = $event->getTarget();
        $module = $view->vars()->module;
        if ($module->getId() != __NAMESPACE__) {
            return;
        }

        $services = $this->getServiceLocator();
        $t = $services->get('MvcTranslator');
        $config = $this->getServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        $html = '<p>';
        $html .= '<strong>';
        $html .= $t->translate('WARNING:'); // @translate
        $html .= '</strong>';
        $html .= '</p>';

        $html .= '<p>';
        $html .= sprintf(
            $t->translate('All contact messages and files will be removed (folder "%s").'), // @translate
            $basePath . '/' . self::STORE_PREFIX
        );
        $html .= '</p>';

        $html .= '<label><input name="remove-contact-us" type="checkbox" form="confirmform">';
        $html .= $t->translate('Remove Contact Us files'); // @translate
        $html .= '</label>';

        echo $html;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // Deprecated. Use resource block layout instead.
        $sharedEventManager->attach(
            'Omeka\Controller\Site\ItemSet',
            'view.show.after',
            [$this, 'handleViewShowAfterResource']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.show.after',
            [$this, 'handleViewShowAfterResource']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Media',
            'view.show.after',
            [$this, 'handleViewShowAfterResource']
        );

        // Display the contact form under item/browse.
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.browse.after',
            [$this, 'handleViewBrowse']
        );

        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'onSettingsFormAddElements']
        );
        $sharedEventManager->attach(
            \Omeka\Form\SiteSettingsForm::class,
            'form.add_elements',
            [$this, 'onSiteSettingsFormAddElements']
        );

        // Display a warn before uninstalling.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Module',
            'view.details',
            [$this, 'warnUninstall']
        );
    }

    public function handleViewShowAfterResource(Event $event): void
    {
        $view = $event->getTarget();
        $resourceName = $view->resource->resourceName();
        $append = $this->getServiceLocator()->get('Omeka\Settings\Site')->get('contactus_append_resource_show', []);
        if (!in_array($resourceName, $append)) {
            return;
        }
        echo $view->contactUs([
            'resource' => $view->resource,
            'attach_file' => false,
            'newsletter_label' => '',
        ]);
    }

    public function handleViewBrowse(Event $event): void
    {
        $append = $this->getServiceLocator()->get('Omeka\Settings\Site')->get('contactus_append_items_browse');
        if (!$append) {
            return;
        }
        $view = $event->getTarget();
        echo $view->contactUs([
            'attach_file' => false,
            'newsletter_label' => '',
        ]);
    }

    public function onSettingsFormAddElements(Event $event)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $forms = $services->get('FormElementManager');
        $form = $event->getTarget();

        $element_groups = $form->getOption('element_groups');
        $element_groups['contact'] = 'Contact'; // @translate
        $form->setOption('element_groups', $element_groups);

        $form->add([
            'name' => 'contactus_notify_recipients',
            'type' => OmekaElement\ArrayTextarea::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Default emails to notify', // @translate
                'info' => 'The default list of recipients to notify, one by row. First email is used for confirmation.', // @translate
            ],
            'attributes' => [
                'id' => 'contactus_notify_recipients',
                'required' => false,
                'placeholder' => 'First email is used for confirmation.
contact@example.org
info@example2.org', // @translate
                'rows' => 5,
                'value' => $settings->get('contactus_notify_recipients', self::SETTINGS['contactus_notify_recipients']),
            ],
        ]);

        $form->add([
            'name' => 'contactus_author',
            'type' => OmekaElement\PropertySelect::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Property where email of the author is stored', // @translate
                'info' => 'Allows visitors to contact authors via an email.', // @translate
                'prepend_value_options' => [
                    'disabled' => 'Disable feature', // @translate
                    'owner' => 'Owner of the resource', // @translate
                ],
                'term_as_value' => true,
            ],
            'attributes' => [
                'id' => 'contactus_author',
                'multiple' => false,
                'class' => 'chosen-select',
                'data-placeholder' => 'Select a property…', // @translate
                'value' => $settings->get('contactus_author', self::SETTINGS['contactus_author']),
            ],
        ]);

        $form->add([
            'name' => 'contactus_author_only',
            'type' => Element\Checkbox::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Send email to author only, not admins (hidden)', // @translate
            ],
            'attributes' => [
                'id' => 'contactus_author_only',
                'value' => $settings->get('contactus_author_only', self::SETTINGS['contactus_author_only']),
            ],
        ]);

        $form->add([
            'name' => 'contactus_send_with_user_email',
            'type' => Element\Checkbox::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Use the user email to send message (warning: many email providers reject them as spam)', // @translate
            ],
            'attributes' => [
                'id' => 'contactus_send_with_user_email',
                'value' => $settings->get('contactus_send_with_user_email', self::SETTINGS['contactus_send_with_user_email']),
            ],
        ]);

        $form->add([
            'name' => 'contactus_create_zip',
            'type' => Element\Radio::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Zipped files to send', // @translate
                'value_options' => [
                    'original' => 'Original', // @translate
                    'large' => 'Large', // @translate
                    'medium' => 'Medium', // @translate
                    'square' => 'Square', // @translate
                ],
            ],
            'attributes' => [
                'id' => 'contactus_create_zip',
                'value' => $settings->get('contactus_create_zip', self::SETTINGS['contactus_create_zip']),
            ],
        ]);

        $form->add([
            'name' => 'contactus_delete_zip',
            'type' => Element\Text::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Remove zip files after some days', // @translate
            ],
            'attributes' => [
                'id' => 'contactus_delete_zip',
                'value' => $settings->get('contactus_delete_zip', self::SETTINGS['contactus_delete_zip']),
            ],
        ]);
    }

    public function onSiteSettingsFormAddElements(Event $event)
    {
        $services = $this->getServiceLocator();
        $forms = $services->get('FormElementManager');
        $form = $event->getTarget();
        $siteSettings = $form->getSiteSettings();

        $element_groups = $form->getOption('element_groups');
        $element_groups['contact'] = 'Contact'; // @translate
        $form->setOption('element_groups', $element_groups);

        $settingValue = fn ($name) => $siteSettings->get($name, self::SITE_SETTINGS[$name] ?? null);

        $form->add([
            'name' => 'contactus_notify_recipients',
            'type' => OmekaElement\ArrayTextarea::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Emails to notify', // @translate
                'info' => 'The list of recipients to notify, one by row. First email is used for confirmation.', // @translate
            ],
            'attributes' => [
                'id' => 'contactus_notify_recipients',
                'required' => false,
                'placeholder' => 'Let empty to use main settings. First email is used for confirmation.
contact@example.org
info@example2.org', // @translate
                'rows' => 5,
                'value' => $settingValue('contactus_notify_recipients'),
            ],
        ]);

        $form->add([
            'name' => 'contactus_notify_subject',
            'type' => Element\Text::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Notification email subject for admin', // @translate
            ],
            'attributes' => [
                'id' => 'contactus_notify_subject',
                'value' => $settingValue('contactus_notify_subject'),
            ],
        ]);

        $form->add([
            'name' => 'contactus_notify_body',
            'type' => Element\Textarea::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Notification message for admin', // @translate
                'info' => 'Possible placeholders: {main_title}, {main_url}, {site_title}, {site_url}, {email}, {name}, {object}, {subject}, {message}, {newsletter}, {ip}.', // @translate
            ],
            'attributes' => [
                'id' => 'contactus_notify_body',
                'rows' => 5,
                'value' => $settingValue('contactus_notify_body'),
            ],
        ]);

        $form->add([
            'name' => 'contactus_confirmation_enabled',
            'type' => Element\Checkbox::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Send a confirmation email', // @translate
            ],
            'attributes' => [
                'id' => 'contactus_confirmation_enabled',
                'value' => $settingValue('contactus_confirmation_enabled'),
            ],
        ]);

        $form->add([
            'name' => 'contactus_confirmation_subject',
            'type' => Element\Text::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Subject of the confirmation email', // @translate
            ],
            'attributes' => [
                'id' => 'contactus_confirmation_subject',
                'value' => $settingValue('contactus_confirmation_subject'),
            ],
        ]);

        $form->add([
            'name' => 'contactus_confirmation_body',
            'type' => Element\Textarea::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Confirmation message', // @translate
                'info' => 'Possible placeholders: {main_title}, {main_url}, {site_title}, {site_url}, {email}, {name}, {object}, {subject}, {message}, {newsletter}, {ip}.', // @translate
            ],
            'attributes' => [
                'id' => 'contactus_confirmation_body',
                'rows' => 5,
                'value' => $settingValue('contactus_confirmation_body'),
            ],
        ]);

        $form->add([
            'name' => 'contactus_confirmation_newsletter_subject',
            'type' => Element\Text::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Subject of the confirmation for subscription to newsletter', // @translate
            ],
            'attributes' => [
                'id' => 'contactus_confirmation_newsletter_subject',
                'value' => $settingValue('contactus_confirmation_newsletter_subject'),
            ],
        ]);

        $form->add([
            'name' => 'contactus_confirmation_newsletter_body',
            'type' => Element\Textarea::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Confirmation message for subscription to newsletter', // @translate
                'info' => 'Possible placeholders: {main_title}, {main_url}, {site_title}, {site_url}, {email}, {object}, {subject}, {ip}.', // @translate
            ],
            'attributes' => [
                'id' => 'contactus_confirmation_newsletter_body',
                'rows' => 5,
                'value' => $settingValue('contactus_confirmation_newsletter_body'),
            ],
        ]);

        $form->add([
            'name' => 'contactus_to_author_subject',
            'type' => Element\Text::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Subject of the email to author', // @translate
            ],
            'attributes' => [
                'id' => 'contactus_to_author_subject',
                'value' => $settingValue('contactus_to_author_subject'),
            ],
        ]);

        $form->add([
            'name' => 'contactus_to_author_body',
            'type' => Element\Textarea::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Message to the author', // @translate
                'info' => 'Possible placeholders: {main_title}, {main_url}, {site_title}, {site_url}, {email}, {name}, {object}, {subject}, {message}, {newsletter}, {ip}.', // @translate
            ],
            'attributes' => [
                'id' => 'contactus_to_author_body',
                'rows' => 5,
                'value' => $settingValue('contactus_to_author_body'),
            ],
        ]);

        $form->add([
            'name' => 'contactus_antispam',
            'type' => Element\Checkbox::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Enable simple antispam for visitors', // @translate
            ],
            'attributes' => [
                'id' => 'contactus_antispam',
                'value' => $settingValue('contactus_antispam'),
            ],
        ]);

        $form->add([
            'name' => 'contactus_questions',
            'type' => OmekaElement\ArrayTextarea::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'List of antispam questions/answers', // @translate
                'info' => 'See the block "Contact us" for a simple list. Separate questions and answer with a "=". Questions may be translated.', // @translate
                'as_key_value' => true,
            ],
            'attributes' => [
                'id' => 'contactus_questions',
                'placeholder' => 'How many are zero plus 1 (in number)? = 1
How many are one plus 1 (in number)? = 2', // @translate
                'rows' => 5,
                'value' => $settingValue('contactus_questions'),
            ],
        ]);

        $form->add([
            'name' => 'contactus_append_resource_show',
            'type' => Element\MultiCheckbox::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Append to resource page (deprecated, for themes without resource block)', // @translate
                'value_options' => [
                    'items' => 'Items', // @translate
                    'medias' => 'Medias', // @translate
                    'item_sets' => 'Item sets', // @translate
                ],
            ],
            'attributes' => [
                'id' => 'contactus_append_resource_show',
                'value' => $settingValue('contactus_append_resource_show'),
            ],
        ]);

        $form->add([
            'name' => 'contactus_append_items_browse',
            'type' => Element\Checkbox::class,
            'options' => [
                'element_group' => 'contact',
                'label' => 'Append to items browse page', // @translate
            ],
            'attributes' => [
                'id' => 'contactus_append_items_browse',
                'value' => $settingValue('contactus_append_items_browse'),
            ],
        ]);

        $inputFilter = $form->getInputFilter();
        $inputFilter->add([
            'name' => 'contactus_append_resource_show',
            'required' => false,
        ]);
    }

    protected function rmDir(string $dirPath): bool
    {
        if (!file_exists($dirPath)) {
            return true;
        }
        if (strpos($dirPath, '/..') !== false || substr($dirPath, 0, 1) !== '/') {
            return false;
        }
        $files = array_diff(scandir($dirPath), ['.', '..']);
        foreach ($files as $file) {
            $path = $dirPath . '/' . $file;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                unlink($path);
            }
        }
        return rmdir($dirPath);
    }
}
