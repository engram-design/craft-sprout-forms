<?php
/**
 * @link      https://sprout.barrelstrengthdesign.com
 * @copyright Copyright (c) Barrel Strength Design LLC
 * @license   https://craftcms.github.io/license
 */

namespace barrelstrength\sproutforms\controllers;

use barrelstrength\sproutbase\SproutBase;
use barrelstrength\sproutforms\elements\Form;
use barrelstrength\sproutforms\elements\Form as FormElement;
use barrelstrength\sproutforms\models\Settings;
use barrelstrength\sproutforms\SproutForms;
use Craft;
use craft\base\ElementInterface;
use craft\db\Table;
use craft\errors\MissingComponentException;
use craft\errors\WrongEditionException;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\records\FieldLayoutTab as FieldLayoutTabRecord;
use craft\web\Controller as BaseController;
use Throwable;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\InvalidRouteException;
use yii\db\StaleObjectException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 *
 * @property FormElement $formModel
 */
class FormsController extends BaseController
{
    /**
     * @throws HttpException
     * @throws InvalidConfigException
     */
    public function init()
    {
        $this->requirePermission('sproutForms-editForms');
        parent::init();
    }

    public function actionIndexTemplate()
    {
        /** @var SproutForms $plugin */
        $plugin = Craft::$app->plugins->getPlugin('sprout-forms');

        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        if ($settings->enableSaveData) {
            return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('sprout-forms/'.$settings->defaultSection));
        }

        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('sprout-forms/forms'));
    }

    /**
     * @param int|null $formId
     * @param null     $settingsSectionHandle
     *
     * @return Response
     * @throws InvalidConfigException
     * @throws MissingComponentException
     */
    public function actionEditSettingsTemplate(int $formId = null, $settingsSectionHandle = null): Response
    {
        $form = SproutForms::$app->forms->getFormById($formId);

        /** @var SproutForms $plugin */
        $plugin = Craft::$app->plugins->getPlugin('sprout-forms');

        $isPro = SproutBase::$app->settings->isEdition('sprout-forms', SproutForms::EDITION_PRO);

        return $this->renderTemplate('sprout-forms/forms/_settings/'.$settingsSectionHandle, [
            'form' => $form,
            'groups' => SproutForms::$app->groups->getAllFormGroups(),
            'groupId' => $form->groupId ?? null,
            'settings' => $plugin->getSettings(),
            'rules' => SproutForms::$app->rules->getRulesByFormId($formId),
            'ruleOptions' => SproutForms::$app->rules->getRuleOptions(),
            'integrations' => SproutForms::$app->integrations->getIntegrationsByFormId($formId),
            'isPro' => $isPro
        ]);
    }

    /**
     * Duplicates an entry.
     *
     * @return FormsController|mixed
     * @throws InvalidRouteException
     */
    public function actionDuplicateForm()
    {
        return $this->runAction('save-form', ['duplicate' => true]);
    }

    /**
     * Save a form
     *
     * @param bool $duplicate
     *
     * @return Response|null
     * @throws BadRequestHttpException
     * @throws Exception
     * @throws MissingComponentException
     * @throws NotFoundHttpException
     * @throws Throwable
     */
    public function actionSaveForm(bool $duplicate = false)
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $form = $this->getFormModel();
        $oldTitleFormat = $form->titleFormat;
        $duplicateForm = null;

        // If we're duplicating the form, swap $form with the duplicate

        if ($duplicate) {
            $duplicateForm = SproutForms::$app->forms->createNewForm(
                $request->getBodyParam('name'),
                $request->getBodyParam('handle')
            );

            if ($duplicateForm) {
                $form->id = $duplicateForm->id;
                $form->uid = $duplicateForm->uid;
            } else {
                throw new Exception('Error creating Form');
            }
        }

        $this->populateFormModel($form);
        $currentTitleFormat = $form->titleFormat;
        $this->prepareFieldLayout($form, $duplicate, $duplicateForm);

        // Save it
        if (!SproutForms::$app->forms->saveForm($form, $duplicate)) {

            Craft::$app->getSession()->setError(Craft::t('sprout-forms', 'Couldn’t save form.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'form' => $form
            ]);

            return null;
        }
        if ($oldTitleFormat !== $currentTitleFormat) {
            SproutForms::$app->entries->resaveElements($form->id);
        }

        Craft::$app->getSession()->setNotice(Craft::t('sprout-forms', 'Form saved.'));

        $_POST['redirect'] = str_replace('{id}', $form->id, $_POST['redirect']);

        return $this->redirectToPostedUrl($form);
    }

    /**
     * Edit a form.
     *
     * @param int|null                          $formId
     * @param FormElement|ElementInterface|null $form
     *
     * @return Response
     * @throws NotFoundHttpException
     * @throws \Exception
     * @throws Throwable
     */
    public function actionEditFormTemplate(int $formId = null, FormElement $form = null): Response
    {
        $isNew = !$formId;

        // Immediately create a new Form
        if ($isNew) {

            // Make sure Pro is installed before we create a new form
            if (!SproutForms::$app->forms->canCreateForm()) {
                throw new WrongEditionException('Please upgrade to Sprout Forms Pro Edition to create unlimited forms.');
            }

            $form = SproutForms::$app->forms->createNewForm();

            if ($form) {
                $url = UrlHelper::cpUrl('sprout-forms/forms/edit/'.$form->id);

                return $this->redirect($url);
            }

            throw new Exception('Unable to create new Form');
        }

        if ($form === null && $formId !== null) {
            $form = SproutForms::$app->forms->getFormById($formId);

            if (!$form) {
                throw new NotFoundHttpException('Form not found');
            }
        }

        $tabs = SproutForms::$app->forms->getTabsForFieldLayout($form);

        return $this->renderTemplate('sprout-forms/forms/_editForm', [
            'form' => $form,
            'formTabs' => $tabs,
            'continueEditingUrl' => 'sprout-forms/forms/edit/{id}'
        ]);
    }

    /**
     * Delete a Form
     *
     * @return Response
     * @throws \Exception
     * @throws Throwable
     * @throws BadRequestHttpException
     */
    public function actionDeleteForm(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        // Get the Form these fields are related to
        $formId = $request->getRequiredBodyParam('formId');
        $form = SproutForms::$app->forms->getFormById($formId);

        if (!$form) {
            throw new NotFoundHttpException('Form not found');
        }

        SproutForms::$app->forms->deleteForm($form);

        return $this->redirectToPostedUrl($form);
    }

    /**
     * @param FormElement $form
     * @param bool        $duplicate
     * @param FormElement $duplicatedForm
     *
     * @throws Throwable
     */
    public function prepareFieldLayout(FormElement $form, $duplicate = false, $duplicatedForm = null)
    {
        // Set the field layout
        $fieldLayout = Craft::$app->getFields()->assembleLayoutFromPost();

        if ($duplicate) {
            $fieldLayout = SproutForms::$app->fields->getDuplicateLayout($duplicatedForm, $fieldLayout);
        }

        // Make sure we have a layout if:
        // 1. Form fails validation due to no fields existing
        // 2. We are saving General Settings and no Layout exists
        if (count($fieldLayout->getFields()) === 0) {
            $fieldLayout = $form->getFieldLayout();
        }

        $fieldLayout->type = FormElement::class;

        $form->setFieldLayout($fieldLayout);

        // Delete any fields removed from the layout
        $deletedFields = Craft::$app->getRequest()->getBodyParam('deletedFields', []);

        if (count($deletedFields) > 0) {
            // Backup our field context and content table
            $oldFieldContext = Craft::$app->content->fieldContext;
            $oldContentTable = Craft::$app->content->contentTable;

            // Set our field content and content table to work with our form output
            Craft::$app->content->fieldContext = $form->getFieldContext();
            Craft::$app->content->contentTable = $form->getContentTable();

            $currentTitleFormat = null;

            foreach ($deletedFields as $fieldId) {
                // If a deleted field is used in the titleFormat setting, update it
                $currentTitleFormat = SproutForms::$app->forms->cleanTitleFormat($fieldId);
                Craft::$app->fields->deleteFieldById($fieldId);
            }

            if ($currentTitleFormat) {
                // update the titleFormat
                $form->titleFormat = $currentTitleFormat;
            }

            // Reset our field context and content table to what they were previously
            Craft::$app->content->fieldContext = $oldFieldContext;
            Craft::$app->content->contentTable = $oldContentTable;
        }
    }

    /**
     * This action allows create a new Tab to current layout
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     */
    public function actionAddFormTab(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('sproutForms-editEntries');

        $request = Craft::$app->getRequest();
        $formId = $request->getBodyParam('formId');
        $name = $request->getBodyParam('name');

        $tab = null;

        if ($formId && $name) {
            $tab = SproutForms::$app->fields->createNewTab($formId, $name);

            if ($tab->id) {
                return $this->asJson([
                    'success' => true,
                    'tab' => [
                        'id' => $tab->id,
                        'name' => $tab->name
                    ]
                ]);
            }
        }

        return $this->asJson([
            'success' => false,
            'errors' => $tab->getErrors()
        ]);
    }

    /**
     * This action allows delete a Tab of the current layout
     *
     * @return Response
     * @throws Throwable
     * @throws StaleObjectException
     * @throws BadRequestHttpException
     */
    public function actionDeleteFormTab(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('sproutForms-editEntries');

        $request = Craft::$app->getRequest();
        $pageId = $request->getBodyParam('id');
        $pageId = str_replace('tab-', '', $pageId);
        $tabRecord = FieldLayoutTabRecord::findOne($pageId);

        if ($tabRecord) {
            $result = $tabRecord->delete();

            if ($result) {
                return $this->asJson([
                    'success' => true
                ]);
            }
        }

        return $this->asJson([
            'success' => false,
            'errors' => $tabRecord->getErrors()
        ]);
    }

    /**
     * This action allows rename a current Tab
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     */
    public function actionRenameFormTab(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('sproutForms-editEntries');

        $request = Craft::$app->getRequest();
        $tabId = $request->getBodyParam('tabId');
        $newName = $request->getBodyParam('newName');

        if ($tabId && $newName) {
            $result = SproutForms::$app->fields->renameTab($tabId, $newName);

            if ($result) {
                return $this->asJson([
                    'success' => true
                ]);
            }
        }

        return $this->asJson([
            'success' => false,
            'errors' => Craft::t('sprout-forms', 'Unable to rename tab')
        ]);
    }

    /**
     * @return \yii\web\Response
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionReorderFormTabs(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $formTabIds = Json::decode(Craft::$app->getRequest()->getRequiredBodyParam('ids'));

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            // Loop through our reordered IDs and update the DB with their new order
            // increment $index by one to avoid using '0' in the sort order
            foreach ($formTabIds as $index => $tabId) {
                $db->createCommand()->update(Table::FIELDLAYOUTTABS, [
                    'sortOrder' => $index + 1
                ], ['id' => $tabId], [], false)->execute();
            }
            $transaction->commit();

            return $this->asJson([
                'success' => true
            ]);
        } catch (\yii\db\Exception $e) {
            $transaction->rollBack();
        }

        return $this->asJson([
            'success' => false,
            'errors' => Craft::t('sprout-forms', 'Unable to rename tab')
        ]);
    }

    /**
     * @return \yii\web\Response
     * @throws \Throwable
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionGetUpdatedLayoutHtml(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $formId = Craft::$app->getRequest()->getBodyParam('formId');
        $form = SproutForms::$app->forms->getFormById($formId);

        SproutForms::$app->forms->saveForm($form);

        $view = Craft::$app->getView();

        $tabs = SproutForms::$app->forms->getTabsForFieldLayout($form);

        $tabsHtml = !empty($tabs) ? $view->renderTemplate('_includes/tabs', [
            'tabs' => $tabs
        ]) : null;

        $contentHtml = $view->renderTemplate('sprout-forms/forms/_editFormContent', [
            'form' => $form,
            'fieldLayout' => $form->getFieldLayout()
        ]);

        return $this->asJson([
            'success' => true,
            'tabsHtml' => $tabsHtml,
            'contentHtml' => $contentHtml,
            'headHtml' => $view->getHeadHtml(),
            'bodyHtml' => $view->getBodyHtml()
        ]);
    }

    /**
     * @return FormElement
     * @throws NotFoundHttpException
     */
    private function getFormModel(): FormElement
    {
        $request = Craft::$app->getRequest();
        $formId = $request->getBodyParam('formId');
        $siteId = $request->getBodyParam('siteId');

        if ($formId) {
            $form = SproutForms::$app->forms->getFormById($formId, $siteId);

            if (!$form) {
                throw new NotFoundHttpException('Form not found');
            }

            // Set oldHandle to the value from the db so we can
            // determine if we need to rename the content table
            $form->oldHandle = $form->handle;
        } else {
            $form = new FormElement();

            if ($siteId) {
                $form->siteId = $siteId;
            }
        }

        return $form;
    }

    /**
     * @param FormElement $form
     */
    private function populateFormModel(FormElement $form)
    {
        $request = Craft::$app->getRequest();

        // Set the form attributes, defaulting to the existing values for whatever is missing from the post data
        $form->groupId = $request->getBodyParam('groupId', $form->groupId);
        $form->name = $request->getBodyParam('name', $form->name);
        $form->handle = $request->getBodyParam('handle', $form->handle);
        $form->displaySectionTitles = $request->getBodyParam('displaySectionTitles', $form->displaySectionTitles);
        $form->redirectUri = $request->getBodyParam('redirectUri', $form->redirectUri);
        $form->saveData = $request->getBodyParam('saveData', $form->saveData);
        $form->submitButtonText = $request->getBodyParam('submitButtonText', $form->submitButtonText);
        $form->titleFormat = $request->getBodyParam('titleFormat', $form->titleFormat);
        $form->formTemplate = $request->getBodyParam('formTemplate', $form->formTemplate);
        $form->enableCaptchas = $request->getBodyParam('enableCaptchas', $form->enableCaptchas);

        if (!$form->titleFormat) {
            $form->titleFormat = "{dateCreated|date('D, d M Y H:i:s')}";
        }

        if (!$form->displaySectionTitles) {
            $form->displaySectionTitles = false;
        }

        if (!$form->saveData) {
            $form->saveData = false;
        }

        if (!$form->enableCaptchas) {
            $form->enableCaptchas = false;
        }
    }
}
