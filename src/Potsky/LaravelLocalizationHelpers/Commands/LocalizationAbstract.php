<?php

namespace Potsky\LaravelLocalizationHelpers\Commands;

use Illuminate\Console\Command;
use Illuminate\Config\Repository;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

abstract class LocalizationAbstract extends Command
{
    /**
     * Config repository.
     *
     * @var \Illuminate\Config\Repository
     */
    protected $configRepository;

    /**
     * functions and method to catch translations
     *
     * @var  array
     */
    protected $trans_methods = array();

    /**
     * functions and method to catch translations
     *
     * @var  array
     */
    protected $editor = '';

    /**
     * Folders to parse for missing translations
     *
     * @var  array
     */
    protected $folders = array();

    /**
     * Folders to parse for missing translations
     *
     * @var  array
     */
    protected $file_pattern = '/^.+\.php$/i';

    /**
     * Never make lemmas containing these keys obsolete
     *
     * @var  array
     */
    protected $never_obsolete_keys = array();

    /**
     * Never manage these lang files
     *
     * @var  array
     */
    protected $ignore_lang_files = array();

    /**
     * Should comands display something
     *
     * @var  boolean
     */
    protected $display = true;

    /**
     * Create a new command instance.
     *
     * @param \Illuminate\Config\Repository $configRepository
     *
     * @return void
     */
    public function __construct( Repository $configRepository )
    {
        $this->trans_methods       = $this->getConfig('trans_methods');
        $this->folders             = $this->getConfig('folders');
        $this->file_pattern        = $this->getConfig('file_pattern', '/^.+\.php$/i');
        $this->ignore_lang_files   = $this->getConfig('ignore_lang_files');
        $this->lang_folder_path    = $this->getConfig('lang_folder_path');
        $this->never_obsolete_keys = $this->getConfig('never_obsolete_keys');
        $this->editor              = $this->getConfig('editor_command_line');
        parent::__construct();
    }

    protected function getConfig($tag, $default = null)
    {
        $laravel = app();
        if (substr($laravel::VERSION, 0, 1) == '5')
        {
            return \Config::get('laravel-localization-helpers.' . $tag, $default);
        }
        else
        {
            return \Config::get('laravel-localization-helpers::config.' . $tag, $default);
        }
    }

    /**
     * Get the lang directory path
     *
     * @return string the path
     */
    protected function get_lang_path()
    {
        if ( empty( $this->lang_folder_path ) ) {
            $paths = array(
                app_path() . DIRECTORY_SEPARATOR . 'lang',
                base_path() . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'lang',
            );
            foreach( $paths as $path ) {
                if ( file_exists( $path ) ) {
                    return $path;
                }
            }

            $this->error( "No lang folder found in these paths:" );
            foreach( $paths as $path ) {
                $this->error( "- " . $path );
            }
            $this->line( '' );
            die();
        }
        else {
            if ( file_exists( $this->lang_folder_path ) ) {
                return $this->lang_folder_path;
            }
            $this->error( 'No lang folder found in your custom path: "' . $this->lang_folder_path . '"' );
            $this->line( '' );
            die();
        }
    }

    /**
     * Display console message
     *
     * @param   string  $s  the message to display
     *
     * @return  void
     */
    public function line( $s ) {
        if ( $this->display ) {
            parent::line( $s );
        }
    }

    /**
     * Display console message
     *
     * @param   string  $s  the message to display
     *
     * @return  void
     */
    public function info( $s ) {
        if ( $this->display ) {
            parent::info( $s );
        }
    }

    /**
     * Display console message
     *
     * @param   string  $s  the message to display
     *
     * @return  void
     */
    public function comment( $s ) {
        if ( $this->display ) {
            parent::comment( $s );
        }
    }

    /**
     * Display console message
     *
     * @param   string  $s  the message to display
     *
     * @return  void
     */
    public function question( $s ) {
        if ( $this->display ) {
            parent::question( $s );
        }
    }

    /**
     * Display console message
     *
     * @param   string  $s  the message to display
     *
     * @return  void
     */
    public function error( $s ) {
        if ( $this->display ) {
            parent::error( $s );
        }
    }


    /**
     * Return an absolute path without predefined variables
     *
     * @param string $path the relative path
     *
     * @return string the absolute path
     */
    protected function get_path($path)
    {
        return str_replace(
            array(
                '%APP',
                '%BASE',
                '%PUBLIC',
                '%STORAGE',
                ),
            array(
                app_path(),
                base_path(),
                public_path(),
                storage_path()
                ),
            $path
            );
    }

    /**
     * Return an relative path to the laravel directory
     *
     * @param string $path the absolute path
     *
     * @return string the relative path
     */
    protected function get_short_path($path)
    {
        return str_replace( base_path() , '' , $path );
    }

    /**
     * return an iterator of files in the provided paths and subpaths
     *
     * @param string $path a source path
     *
     * @return array a list of file paths
     */
    protected function get_source_files($path)
    {
        if ( is_dir( $path ) ) {
            return new \RegexIterator(
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator( $path , \RecursiveDirectoryIterator::SKIP_DOTS ),
                    \RecursiveIteratorIterator::SELF_FIRST,
                    \RecursiveIteratorIterator::CATCH_GET_CHILD // Ignore "Permission denied"
                    ),
                $this->file_pattern,
                \RecursiveRegexIterator::GET_MATCH
                );
        } else {
            return array();
        }
    }

    /**
     * Extract all translations from the provided file
     * Remove all translations containing :
     * - $  -> auto-generated translation cannot be supported
     * - :: -> package translations are not taken in account
     *
     * @param string $path the file path
     *
     * @return array an array dot of found translations
     */
    protected function extract_translation_from_file($path)
    {
        $result = array();
        $string = file_get_contents( $path );
        foreach ( array_flatten( $this->trans_methods ) as $method) {
            preg_match_all( $method , $string , $matches );
            $a = array();
            foreach ( $matches[1] as $k => $v ) {
                if ( strpos( $v , '$' ) !== false ) unset( $matches[1][$k] );
                if ( strpos( $v , '::' ) !== false ) unset( $matches[1][$k] );
            }
            $result = array_merge( $result , array_flip( $matches[1] ) );
        }

        return $result;
    }

}
