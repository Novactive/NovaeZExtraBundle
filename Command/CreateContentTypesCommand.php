<?php
/**
 * NovaeZExtraBundle CheckController
 *
 * @package   Novactive\Bundle\eZExtraBundle
 * @author    Novactive <dir.tech@novactive.com>
 * @copyright 2015 Novactive
 * @license   https://github.com/Novactive/NovaeZSEOBundle/blob/master/LICENSE MIT Licence
 */
namespace Novactive\Bundle\eZExtraBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use eZ\Publish\API\Repository\Repository;
use PhpOffice\PhpSpreadsheet\IOFactory;
use eZ\Publish\Core\Base\Exceptions\ContentTypeFieldDefinitionValidationException;
use eZ\Publish\Core\FieldType\ValidationError;

/**
 * Class CreateContentTypesCommand
 */
class CreateContentTypesCommand extends ContainerAwareCommand
{
    /**
     * Repository eZ Publish
     *
     * @var Repository
     */
    protected $eZPublishRepository;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('novaezextra:contenttypes:create')
            ->setDescription('Create/Update the Content Types from an Excel Content Type Model')
            ->addArgument('file', InputArgument::REQUIRED, 'XLSX File to import')
            ->addArgument('tr', InputArgument::OPTIONAL, 'Translation of contentType (eng-GB, fre-FR...)')
            ->addArgument(
                'content_type_group_identifier',
                InputArgument::OPTIONAL,
                'Content type group identifier (Content, Contenu, Custom group...)'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $translation       = $input->getArgument('tr');
        $translationHelper = $this->getContainer()->get('ezpublish.translation_helper');
        $availableLanguage = $translationHelper->getAvailableLanguages();
        if (is_null($translation) || !in_array($translation, $availableLanguage)) {
            $translation = "eng-GB";
        }

        $filepath = $input->getArgument('file');
        if (!file_exists($filepath)) {
            $output->writeln("<error>{$filepath} not found.</error>");

            return false;
        }

        $spreadsheet = IOFactory::load($filepath);
        if (!$spreadsheet) {
            $output->writeln("<error>Failed to load data</error>");

            return false;
        }

        $contentTypeManager = $this->getContainer()->get("novactive.ezextra.content_type.manager");

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $excludedTemplatesSheets = ["ContentType Template", "FieldTypes"];
            if (in_array($worksheet->getTitle(), $excludedTemplatesSheets)) {
                continue;
            }
            $output->writeln($worksheet->getTitle());

            // Mapping

            $lang                     = $translation;
            $contentTypeName          = $worksheet->getCell("B2")->getValue();
            $contentTypeIdentifier    = $worksheet->getCell("B3")->getValue();
            $contentTypeDescription   = $worksheet->getCell("B4")->getValue();
            $contentTypeObjectPattern = $worksheet->getCell("B5")->getValue();
            $contentTypeURLPattern    = $worksheet->getCell("B6")->getValue();
            $contentTypeContainer     = $worksheet->getCell("B7")->getValue() == "yes" ? true : false;

            if (!$contentTypeDescription) {
                $contentTypeDescription = "Content Type Description - To be defined";
            }
            $contentTypeData                 = [
                'nameSchema'     => $contentTypeObjectPattern,
                'urlAliasSchema' => $contentTypeURLPattern,
                'isContainer'    => $contentTypeContainer,
                'names'          => $contentTypeName,
                'descriptions'   => $contentTypeDescription,
            ];
            $contentTypeFieldDefinitionsData = [];
            foreach ($worksheet->getRowIterator() as $row) {
                $rowIndex        = $row->getRowIndex();
                $fieldIdentifier = $worksheet->getCell("B{$rowIndex}")->getValue();
                if (($rowIndex) >= 11 && ($fieldIdentifier != '')) {
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(false); // Loop all cells, even if it is not set
                    $contentTypeFieldsData = [];
                    foreach ($cellIterator as $cell) {
                        if (!is_null($cell)) {
                            $cellValue = trim($cell->getValue());
                            switch ($cell->getColumn()) {
                                case "A":
                                    $contentTypeFieldsData['names'] = [$lang => $cellValue];
                                    break;
                                case "B":
                                    $contentTypeFieldsData['identifier'] = $cellValue;
                                    break;
                                case "C":
                                    $contentTypeFieldsData['type'] = $cellValue;
                                    break;
                                case "D":
                                    if (!$cellValue) {
                                        $cellValue = "";
                                    }
                                    $contentTypeFieldsData['descriptions'] = [$lang => $cellValue];
                                    break;
                                case "E":
                                    $contentTypeFieldsData['isRequired'] = $cellValue == "yes" ? true : false;
                                    break;
                                case "F":
                                    $contentTypeFieldsData['isSearchable'] = $cellValue == "yes" ? true : false;
                                    break;
                                case "G":
                                    $contentTypeFieldsData['isTranslatable'] = $cellValue == "yes" ? true : false;
                                    break;
                                case "H":
                                    $contentTypeFieldsData['fieldGroup'] = $cellValue;
                                    break;
                                case "I":
                                    $contentTypeFieldsData['position'] = intval($cellValue);
                                    break;
                                case "J":
                                    $contentTypeFieldsData['settings'] = $cellValue;
                                    break;
                            }
                        }
                    }
                    $contentTypeFieldDefinitionsData[] = $contentTypeFieldsData;
                }
            }
            $contentTypeGroupIdentifierParam = $input->getArgument('content_type_group_identifier');
            $contentTypeGroups               = $contentTypeManager->getContentTypeService()->loadContentTypeGroups();
            $contentTypeGroupIdentifier      = null;
            foreach ($contentTypeGroups as $contentTypeGroup) {
                if (!is_null($contentTypeGroupIdentifierParam) &&
                    $contentTypeGroup->attribute('identifier') == $contentTypeGroupIdentifierParam
                ) {
                    $contentTypeGroupIdentifier = $contentTypeGroupIdentifierParam;
                    break;
                }
            }
            $contentTypeGroupIdentifier = (!is_null(
                $contentTypeGroupIdentifier
            ) ? $contentTypeGroupIdentifier : $contentTypeGroups[0]->attribute('identifier'));
            try {
                $contentTypeManager->createUpdateContentType(
                    $contentTypeIdentifier,
                    $contentTypeGroupIdentifier,
                    $contentTypeData,
                    $contentTypeFieldDefinitionsData,
                    [],
                    $lang
                );
            } catch (ContentTypeFieldDefinitionValidationException $e) {
                $output->writeln("<error>{$e->getMessage()}</error>");
                $errors = $e->getFieldErrors();
                foreach ($errors as $attrIdentifier => $errorArray) {
                    $output->write("\t<info>{$contentTypeName}: {$attrIdentifier}</info>");
                    foreach ($errorArray as $error) {
                        /** @var ValidationError $error */
                        $message = $error->getTranslatableMessage()->message;
                        $output->writeln("\t<comment>{$message}</comment>");
                    }
                }
            }
        }
        $output->writeln("Done");
        unset($input); // phpmd tricks
        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $input;// phpmd trick
        $output;// phpmd trick
        $this->eZPublishRepository = $this->getContainer()->get("ezpublish.api.repository");
        $this->eZPublishRepository->setCurrentUser($this->eZPublishRepository->getUserService()->loadUser(14));
    }
}
