<?php
/*
 * This file is part of Pomm's Cli package.
 *
 * (c) 2014 Grégoire HUBERT <hubert.greg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PommProject\Cli\Generator;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\TableSeparator;

use PommProject\Foundation\Session;
use PommProject\Foundation\Inspector;
use PommProject\Foundation\Inflector;
use PommProject\Foundation\ConvertedResultIterator;

use PommProject\Cli\Generator\BaseGenerator;
use PommProject\Cli\Exception\GeneratorException;

/**
 * StructureGenerator
 *
 * Generate a RowStructure file from relation inspection.
 *
 * @package Cli
 * @copyright 2014 Grégoire HUBERT
 * @author Grégoire HUBERT
 * @license X11 {@link http://opensource.org/licenses/mit-license.php}
 */
class StructureGenerator extends BaseGenerator
{
    protected $schema;
    protected $relation;
    protected $filename;
    protected $namespace;

    /*
     * __construct
     *
     * Constructor
     *
     * @access public
     * @param  Session $session
     * @param  string  $relation
     * @param  string  $filename
     * @param  string  $namespace
     * @return void
     */
    public function __construct(Session $session, $schema, $relation, $filename, $namespace)
    {
        parent::setSession($session);

        $this->schema    = $schema;
        $this->relation  = $relation;
        $this->filename  = $filename;
        $this->namespace = $namespace;
    }

    /**
     * generate
     *
     * Generate structure file.
     *
     * @access public
     * @param  InputInterface $input
     * @param  OutputInterface $output
     * @return null
     */
    public function generate(InputInterface $input, OutputInterface $output)
    {
        $table_oid          = $this->checkRelationInformation();
        $field_informations = $this->getFieldInformation($table_oid);
        $primary_key        = $this->getPrimaryKey($table_oid);
        $table_comment      = $this->getTableComment($table_oid);

        if (file_exists($this->filename)) {
            $output->writeln(
                sprintf(
                    "<fg=cyan>Overwriting</fg=cyan> file '%s'.",
                    $this->filename
                )
            );
        } else {
            $output->writeln(
                sprintf(
                    "<fg=yellow>Creating</fg=yellow> file '%s'.",
                    $this->filename
                )
            );
        }

        if ($output->isVerbose()) {
            $table = $this->createTableHelper($output);
            $table->addRow(['relation :', $this->relation]);
            $table->addRow(['namespace :', $this->namespace]);
            $table->setStyle('borderless')->render();
        }

        if ($output->isVeryVerbose()) {
            $table = $this->createTableHelper($output);
            $table->setHeaders(['field', 'type']);

            foreach ($field_informations as $result) {
                $table->addRow([$result['name'], $result['type']]);
            }
            $table->render();
        }

        $this->saveFile(
            $this->filename,
            $this->mergeTemplate(
                [
                    'namespace'     => $this->namespace,
                    'entity'        => Inflector::studlyCaps($this->relation),
                    'relation'      => $this->relation,
                    'primary_key'   => join(
                        ', ',
                        array_map(
                            function($val) { return sprintf("'%s'", $val); },
                            $primary_key
                        )
                    ),
                    'add_fields'    => $this->formatAddFields($field_informations),
                    'table_comment' => $this->createPhpDocBlockFromText($table_comment),
                    'fields_comment' => $this->formatFieldsComment($field_informations),
                ]
            )
        );
    }

    /**
     * formatAddFields
     *
     * Format 'addField' method calls.
     *
     * @access protected
     * @param  ConvertedResultIterator $field_informations
     * @return string
     */
    protected function formatAddFields(ConvertedResultIterator $field_informations)
    {
        $strings = [];
        foreach ($field_informations as $info) {
            $strings[] = sprintf(
                "            ->addField('%s', '%s')",
                $info['name'],
                $info['type']
            );
        }

        return join("\n", $strings);
    }

    /**
     * formatFieldsComment
     *
     * Format fields comment to be in the class comment. This is because there
     * can be very long comments or comments with carriage returns. It is
     * furthermore more convenient to get all the descriptions in the head of
     * the generated class.
     *
     * @access protectd
     * @param  ConvertedResultIterator $field_informations
     * @return string
     */
    protected function formatFieldsComment(ConvertedResultIterator $field_informations)
    {
        $comments = [];
        foreach ($field_informations as $info) {

            if ($info['comment'] === null) {
                continue;
            }

            $comments[] = sprintf(" * %s:", $info['name']);
            $comments[] = $this->createPhpDocBlockFromText($info['comment']);
        }

        return join("\n", $comments);
    }

    /**
     * createPhpDocBlockFromText
     *
     * Format a text into a PHPDoc comment block.
     *
     * @access protected
     * @param  string $text
     * @return string
     */
    protected function createPhpDocBlockFromText($text)
    {
        return join(
            "\n",
            array_map(
                function($line) { return ' *   '.$line; },
                explode("\n", wordwrap($text))
            )
        );
    }

    /**
     * checkRelationInformation
     *
     * Check if the given schema and relation exist. If so, the table oid is
     * returned, otherwise a GeneratorException is thrown.
     *
     * @access private
     * @throw  GeneratorException
     * @return int     $oid
     */
    private function checkRelationInformation()
    {
        if ($this->getInspector()->getSchemaOid($this->schema) === null) {
            throw new GeneratorException(sprintf("Schema '%s' not found.", $this->schema));
        }

        $table_oid = $this->getInspector()->getTableOid($this->schema, $this->relation);

        if ($table_oid === null) {
            throw new GeneratorException(
                sprintf(
                    "Relation '%s' could not be found in schema '%s'.",
                    $this->relation,
                    $this->schema
                )
            );
        }

        return $table_oid;
    }

    /**
     * getFieldInformation
     *
     * Fetch a table field information.
     *
     * @access protected
     * @param  int       $table_oid
     * @return array     $informations
     */
    protected function getFieldInformation($table_oid)
    {
        $fields_info = $this
            ->getInspector()
            ->getTableFieldInformation($table_oid)
            ;

        if ($fields_info === null) {
            throw new GeneratorException(
                sprintf(
                    "Error while fetching fields information for table oid '%s'.",
                    $table_oid
                )
            );
        }

        return $fields_info;
    }

    /**
     * getPrimaryKey
     *
     * Return the primary key of a relation if any.
     *
     * @access protected
     * @param  string $table_oid
     * @return array  $primary_key
     */
    protected function getPrimaryKey($table_oid)
    {
        $primary_key = $this
            ->getInspector()
            ->getPrimaryKey($table_oid)
            ;

        return $primary_key;
    }

    /**
     * getTableComment
     *
     * Grab table comment from database.
     *
     * @access protected
     * @param  int $table_oid
     * @return string|null
     */
    protected function getTableComment($table_oid)
    {
        $comment = $this
            ->getInspector()
            ->getTableComment($table_oid)
            ;

        return $comment;
    }

    /**
     * getCodeTemplate
     *
     * @see BaseGenerator
     */
    protected function getCodeTemplate()
    {
        return <<<'_'
<?php
/**
 * This file has been automaticaly generated by Pomm Cli package.
 * You MIGHT NOT edit this file as your changes will be lost at next
 * generation.
 *
 * Class and fields comments are inspected from table and fields comments.
 * @see http://www.postgresql.org/docs/9.0/static/sql-comment.html
 */

namespace {:namespace:};

use PommProject\ModelManager\Model\RowStructure;

/**
 * {:entity:}
 *
 * Structure class for relation {:relation:}.
{:table_comment:}
 *
{:fields_comment:}
 *
 * @see RowStructure
 */
class {:entity:} extends RowStructure
{
    /**
     * initialize
     *
     * @see RowStructure
     */
    protected function initialize()
    {
        $this
            ->setRelation('{:relation:}')
            ->setPrimaryKey([{:primary_key:}])
{:add_fields:}
            ;
    }
}
_;
    }
}
