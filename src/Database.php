<?php  namespace Filebase;

use Exception;
use Filebase\Config;
use Filebase\Cache;
use Filebase\Filesystem;
use Filebase\Document;
use Filebase\Backup;
use Filebase\Format\DecodingException;
use Filebase\Format\EncodingException;
use Filebase\Filesystem\SavingException;
use Filebase\Filesystem\ReadingException;
use Filebase\Filesystem\FilesystemException;

class Database
{

    /**
    * VERSION
    *
    * Stores the version of Filebase
    * use $db->getVersion()
    */
    const VERSION = '1.0.20';


    //--------------------------------------------------------------------

    /**
    * $config
    *
    * Stores all the configuration object settings
    * \Filebase\Config
    */
    protected $config;


    /**
     * Database constructor.
     *
     * @param array $config
     *
     * @throws FilesystemException
     */
    public function __construct(array $config = [])
    {
        $this->config = new Config($config);

        // if we are set to read only, don't care to look at the directory.
        if ($this->config->read_only === true) return false;

        // Check directory and create it if it doesn't exist
        if (!is_dir($this->config->dir))
        {
            if (!@mkdir($this->config->dir, 0777, true))
            {
                throw new FilesystemException(sprintf('`%s` doesn\'t exist and can\'t be created.', $this->config->dir));
            }
        }
        else if (!is_writable($this->config->dir))
        {
            throw new FilesystemException(sprintf('`%s` is not writable.', $this->config->dir));
        }
    }


    //--------------------------------------------------------------------


    /**
    * version
    *
    * gets the Filebase version
    *
    * @return VERSION
    */
    public function version()
    {
        return self::VERSION;
    }


    //--------------------------------------------------------------------


    /**
    * findAll()
    *
    * Finds all documents in database directory.
    * Then returns you a list of those documents.
    *
    * @param bool $include_documents (include all document objects in array)
    * @param bool $data_only (if true only return the documents data not the full object)
    *
    * @return array $items
    */
    public function findAll($include_documents = true, $data_only = false)
    {
        $format = $this->config->format;

        $file_extension = $format::getFileExtension();
        $file_location  = $this->config->dir.'/';

        $all_items = Filesystem::getAllFiles($file_location, $file_extension);
        if ($include_documents==true)
        {
            $items = [];

            foreach($all_items as $a)
        	{
                if ($data_only === true)
                {
                    $items[] = $this->get($a)->getData();
                }
                else
                {
                    $items[] = $this->get($a);
                }
        	}

            return $items;
        }

        return $all_items;
    }


    //--------------------------------------------------------------------


    /**
    * get
    *
    * retrieves a single result (file)
    *
    * @param mixed $id
    *
    * @return $document \Filebase\Document object
    */
    public function get($id)
    {
        $content = $this->read($id);

        $document = new Document($this);
        $document->setId($id);

        if ($content)
        {
            if (isset($content['__created_at'])) $document->setCreatedAt($content['__created_at']);
            if (isset($content['__updated_at'])) $document->setUpdatedAt($content['__updated_at']);

            $this->set($document,(isset($content['data']) ? $content['data'] : []));
        }

        return $document;
    }


    //--------------------------------------------------------------------


    /**
    * has
    *
    * Check if a record already exists
    *
    * @param mixed $id
    *
    * @return bool true/false
    */
    public function has($id)
    {
        $format = $this->config->format;
        $record = Filesystem::read( $this->config->dir.'/'.Filesystem::validateName($id, $this->config->safe_filename).'.'.$format::getFileExtension() );

        return $record ? true : false;
    }


    //--------------------------------------------------------------------


    /**
    * backup
    *
    * @param string $location (optional)
    *
    * @return $document \Filebase\Backup object
    */
    public function backup($location = '')
    {
        if ($location)
        {
            return new Backup($location, $this);
        }

        return new Backup($this->config->backupLocation, $this);
    }


    //--------------------------------------------------------------------


    /**
    * set
    *
    * @param $document \Filebase\Document object
    * @param mixed $data should be an array
    *
    * @return $document \Filebase\Document object
    */
    public function set(Document $document, $data)
    {
        if ($data)
        {
            foreach($data as $key => $value)
            {
                if (is_array($value)) $value = (array) $value;
                $document->{$key} = $value;
            }
        }

        return $document;
    }


    //--------------------------------------------------------------------


    /**
    * count
    *
    *
    * @return int $total
    */
    public function count()
    {
        return count($this->findAll(false));
    }


    //--------------------------------------------------------------------


    /**
    * save
    *
    * @param $document \Filebase\Document object
    * @param mixed $data should be an array, new data to replace all existing data within
    *
     * @throws Exception|SavingException
    * @return (bool) true or false if file was saved
    */
    public function save(Document $document, $wdata = '')
    {
        if ($this->config->read_only === true)
        {
            throw new SavingException("This database is set to be read-only. No modifications can be made.");
        }

        $format         = $this->config->format;
        $id             = $document->getId();
        $file_extension = $format::getFileExtension();
        $file_location  = $this->config->dir.'/'.Filesystem::validateName($id, $this->config->safe_filename).'.'.$file_extension;
        $created        = $document->createdAt(false);

        if (isset($wdata) && $wdata !== '')
        {
            $document = new Document( $this );
            $document->setId($id);
            $document->set($wdata);
            $document->setCreatedAt($created);
        }

        if (!Filesystem::read($file_location) || $created==false)
        {
            $document->setCreatedAt(time());
        }

        $document->setUpdatedAt(time());

        try {
            $data = $format::encode( $document->saveAs(), $this->config->pretty );
        } catch (EncodingException $e) {
            // TODO: add logging
            throw new SavingException("Can not encode document.", 0, $e);
        }

        if (Filesystem::write($file_location, $data))
        {
            $this->flushCache();

            return $document;
        }
        else
        {
            return false;
        }
    }


    //--------------------------------------------------------------------


    /**
    * query
    *
    *
    */
    public function query()
    {
        return new Query($this);
    }


    //--------------------------------------------------------------------


    /**
     * @param $name
     * @return bool
     * @throws Exception|ReadingException
     */
    protected function read($name)
    {
        $format = $this->config->format;

        $file = Filesystem::read(
            $this->config->dir . '/'
            . Filesystem::validateName($name, $this->config->safe_filename) 
            . '.' . $format::getFileExtension()
        );

        if (!$file) {
            /**
             * FIXME: shouldn't we raise an exception in this case? + implement add/create method?
             * or use $this->has (maybe not here but inside $this->get method) to verify that document exists
             * if not than use add/create method?
             * or remove this exception and allow creating new document with this method?
             */
//            throw new ReadingException("Document '{$name}' does not exists.");
        }

        return $format::decode($file);
    }


    //--------------------------------------------------------------------


    /**
    * delete
    *
    * @param $document \Filebase\Document object
    * @return (bool) true/false if file was deleted
    */
    public function delete(Document $document)
    {
        if ($this->config->read_only === true)
        {
            throw new Exception("This database is set to be read-only. No modifications can be made.");
        }

        $format = $this->config->format;

        $d = Filesystem::delete($this->config->dir.'/'.Filesystem::validateName($document->getId(), $this->config->safe_filename).'.'.$format::getFileExtension());

        $this->flushCache();

        return $d;
    }


    //--------------------------------------------------------------------

    /**
    * truncate
    *
    * Alias for flush(true)
    *
    * @return @see flush
    */
    public function truncate()
    {
        return $this->flush(true);
    }


    //--------------------------------------------------------------------


    /**
    * flush
    *
    * This will DELETE all the documents within the database
    *
    * @param bool $confirm (confirmation before proceeding)
    * @return void
    */
    public function flush($confirm = false)
    {
        if ($this->config->read_only === true)
        {
            throw new Exception("This database is set to be read-only. No modifications can be made.");
        }

        if ($confirm===true)
        {
            $format = $this->config->format;
            $documents = $this->findAll(false);
            foreach($documents as $document)
            {
                Filesystem::delete($this->config->dir.'/'.$document.'.'.$format::getFileExtension());
            }

            if ($this->count() === 0)
            {
                return true;
            }
            else
            {
                throw new Exception("Could not delete all database files in ".$this->config->dir);
            }
        }
        else
        {
            throw new Exception("Database Flush failed. You must send in TRUE to confirm action.");
        }
    }


    //--------------------------------------------------------------------


    /**
    * flushCache
    *
    *
    */
    public function flushCache()
    {
        if ($this->getConfig()->cache===true)
        {
            $cache = new Cache($this);
            $cache->flush();
        }
    }


    //--------------------------------------------------------------------


    /**
    * toArray
    *
    * @param \Filebase\Document
    * @return array
    */
    public function toArray(Document $document)
    {
        return $this->objectToArray( $document->getData() );
    }


    //--------------------------------------------------------------------


    /**
    * objectToArray
    *
    */
    public function objectToArray($obj)
    {
        if (!is_object($obj) && !is_array($obj))
        {
            return $obj;
        }

        $arr = [];
        foreach ($obj as $key => $value)
        {
            $arr[$key] = $this->objectToArray($value);
        }

        return $arr;
    }


    //--------------------------------------------------------------------


    /**
    * getConfig
    *
    * @return $config
    */
    public function getConfig()
    {
        return $this->config;
    }

}
