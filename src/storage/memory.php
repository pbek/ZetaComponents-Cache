<?php
/**
 * File containing the ezcCacheStorageMemory class.
 *
 * @package Cache
 * @version //autogentag//
 * @copyright Copyright (C) 2005-2007 eZ systems as. All rights reserved.
 * @license http://ez.no/licenses/new_bsd New BSD License
 * @filesource
 */

/**
 * Base abstract class for all memory storage classes.
 *
 * Abstract classes extending this class:
 *  - {@link ezcCacheStorageMemcache}
 *  - {@link ezcCacheStorageApc}
 *
 * Implementations derived from this class and its descendants:
 *  - {@link ezcCacheStorageMemcachePlain}
 *  - {@link ezcCacheStorageApcPlain}
 *  - {@link ezcCacheStorageFileApcArray}
 *
 * @package Cache
 * @version //autogentag//
 */
abstract class ezcCacheStorageMemory extends ezcCacheStorage
{
    /**
     * Holds the memory backend object which communicates with the memory handler
     * (Memcache, APC).
     *
     * @var ezcCacheMemoryBackend
     */
    protected $backend;

    /**
     * Holds the name of the memory backend.
     *
     * @var string
     */
    protected $backendName;

    /**
     * Holds the name of the registry.
     *
     * @var string
     */
    protected $registryName;

    /**
     * Holds the registry.
     *
     * @var array(mixed)
     */
    protected $registry = array();

    /**
     * Holds the search registry.
     *
     * @var array(mixed)
     */
    protected $searchRegistry = array();

    /**
     * Creates a new cache storage in the given location.
     *
     * Options can contain the 'ttl' (Time-To-Live). Specific implementations
     * can have additional options.
     *
     * @throws ezcBasePropertyNotFoundException
     *         If you tried to set a non-existent option value. The accepted
     *         options depend on the ezcCacheStorage implementation and may
     *         vary.
     *
     * @param string $location Path to the cache location. Null for
     *                         memory-based storage and an existing
     *                         writeable path for file or memory/file
     *                         storage.
     * @param array(string=>string) $options Options for the cache
     */
    public function __construct( $location, array $options = array() )
    {
        parent::__construct( $location, array() );
    }

    /**
     * Stores data to the cache storage under the key $id.
     *
     * The type of cache data which is expected by an ezcCacheStorageMemory
     * implementation depends on the backend. In most cases strings and arrays
     * will be accepted, in some rare cases only strings might be accepted.
     *
     * Using attributes you can describe your cache data further. This allows
     * you to deal with multiple cache data at once later. Some
     * ezcCacheStorageMemory implementations also use the attributes for storage
     * purposes. Attributes form some kind of "extended ID".
     *
     * @return string The ID string of the newly cached data
     *
     * @param string $id Unique identifier for the data
     * @param mixed $data The data to store
     * @param array(string=>string) $attributes Attributes describing the cached data
     */
    public function store( $id, $data, $attributes = array() )
    {
        // Generate the Identifier
        $identifier = $this->generateIdentifier( $id, $attributes );
        $location = $this->properties['location'];

        if ( isset( $this->registry[$location][$id][$identifier] ) )
        {
            unset( $this->registry[$location][$id][$identifier] );
        }

        // Prepare the data
        $dataStr = $this->prepareData( $data );

        // Store the data
        $this->registerIdentifier( $id, $attributes, $identifier );
        if ( !$this->backend->store( $identifier, $dataStr, $this->properties['options']['ttl'] ) )
        {
            $exceptionClass = "ezcCache{$this->backendName}Exception";
            throw new $exceptionClass( "{$this->backendName} store failed." );
        }

        return $id;
    }

    /**
     * Restores the data from the cache.
     *
     * During access to cached data the caches are automatically
     * expired. This means, that the ezcCacheStorageMemory object checks
     * before returning the data if it's still actual. If the cache
     * has expired, data will be deleted and false is returned.
     *
     * You should always provide the attributes you assigned, although
     * the cache storages must be able to find a cache ID even without
     * them. BEWARE: Finding cache data only by ID can be much
     * slower than finding it by ID and attributes.
     *
     * @param string $id The item ID to restore
     * @param array(string=>string) $attributes Attributes describing the data to restore
     * @param bool $search Wheather to search for items if not found directly
     * @return mixed The cached data on success, otherwise false
     */
    public function restore( $id, $attributes = array(), $search = false )
    {
        // Generate the Identifier
        $identifier = $this->generateIdentifier( $id, $attributes );
        $location = $this->properties['location'];

        // Creates a registry object
        if ( !isset( $this->registry[$location][$id][$identifier] ) )
        {
            if ( !isset( $this->registry[$location] ) )
            {
                $this->registry[$location] = array();
            }
            if ( !isset( $this->registry[$location][$id] ) )
            {
                $this->registry[$location][$id] = array();
            }
            $this->registry[$location][$id][$identifier] = $this->fetchData( $identifier, true );
        }

        // Makes sure a cache exists
        if ( $this->registry[$location][$id][$identifier] === false )
        {
            if ( $search === true
                 && count( $identifiers = $this->search( $id, $attributes ) ) === 1 )
            {
                $identifier = $identifiers[0][2];
                $this->registry[$location][$id][$identifier] = $this->fetchData( $identifier, true );
            }
            else
            {
                // There are more elements found during search, so false is returned
                return false;
            }
        }

        // Make sure the data is not supposed to be expired
        if ( $this->properties['options']['ttl'] !== false 
             && $this->calcLifetime( $identifier, $this->registry[$location][$id][$identifier] ) > $this->properties['options']['ttl'] )
        {
            $this->delete( $id, $attributes, false );
            return false;
        }

        // Return the data
        $data = is_object( $this->registry[$location][$id][$identifier] ) ? $this->registry[$location][$id][$identifier]->data : false;
        if ( $data !== false )
        {
            return $data;
        }
        else
        {
            return false;
        }
    }

    /**
     * Deletes the data associated with $id or $attributes from the cache.
     *
     * Additional attributes provided will matched additionally. This can give
     * you an immense speed improvement against just searching for ID (see
     * {@link ezcCacheStorage::restore()}).
     *
     * If you only provide attributes for deletion of cache data, all cache
     * data matching these attributes will be purged.
     *
     * @throws ezcBaseFilePermissionException
     *         If an already existsing cache file could not be unlinked.
     *         This exception means most likely that your cache directory
     *         has been corrupted by external influences (file permission
     *         change).
     *
     * @param string $id The item ID to purge
     * @param array(string=>string) $attributes Attributes describing the data to restore
     * @param bool $search Wheather to search for items if not found directly
     */
    public function delete( $id = null, $attributes = array(), $search = false )
    {
        // Generate the Identifier
        $identifier = $this->generateIdentifier( $id, $attributes );
        $location = $this->properties['location'];

        // Finds the caches that require deletion
        $delCaches = array();
        if ( $this->fetchData( $identifier ) !== false )
        {
            $delCaches[] = array( $id, $attributes, $identifier );
        }
        else if ( $search === true )
        {
            $delCaches = $this->search( $id, $attributes );
        }

        // Process the caches to delete
        $identifiers = array();
        foreach ( $delCaches as $cache )
        {
            $this->backend->delete( $cache[2] );
            $this->unRegisterIdentifier( $cache[0], $cache[1], $cache[2], true );
            if ( isset( $this->registry[$location][$cache[0]][$cache[2]] ) )
            {
                unset( $this->registry[$location][$cache[0]][$cache[2]] );
            }
        }
        $this->storeSearchRegistry();
    }

    /**
     * Returns the number of items in the cache matching a certain criteria.
     *
     * This method determines if cache data described by the given ID and/or
     * attributes exists. It returns the number of cache data items found.
     *
     * @param string $id The item ID
     * @param array(string=>string) $attributes Attributes describing the data
     * @return int Number of data items matching the criteria
     */
    public function countDataItems( $id = null, $attributes = array() )
    {
        return count( $this->search( $id, $attributes ) );
    }

    /**
     * Returns the time in seconds which remains for a cache object, before it
     * gets outdated. In case the cache object is already outdated or does not
     * exists, this method returns 0.
     *
     * @param string $id The item ID
     * @param array(string=>string) $attributes Attributes describing the data
     * @return int The remaining lifetime (0 if it does not exist or outdated)
     */
    public function getRemainingLifetime( $id, $attributes = array() )
    {
        if ( count( $this->search( $id, $attributes ) ) > 0 ) 
        {
            $identifier = $this->generateIdentifier( $id, $attributes );
            $lifetime = $this->calcLifetime( $identifier );
            return ( ( $remaining = ( $this->properties['options']['ttl'] - $lifetime ) ) > 0 ) ? $remaining : 0;
        }
        return 0;
    }

    /**
     * Searches the storage for data defined by ID and/or attributes.
     *
     * @param string $id The item ID
     * @param array(string=>string) $attributes Attributes describing the data
     * @return array(mixed)
     */
    protected function search( $id = null, $attributes = array() )
    {
        // Grabs the identifier registry
        $this->fetchSearchRegistry();

        // Grabs the $location
        $location = $this->properties['location'];

        // Finds all in case of empty $id and $attributes
        if ( $id === null
             && empty( $attributes )
             && isset( $this->searchRegistry[$location] )
             && is_array( $this->searchRegistry[$location] ) )
        {
            $itemArr = array();
            foreach ( $this->searchRegistry[$location] as $idArr )
            {
                foreach ( $idArr as $registryObj )
                {
                    if ( !is_null( $registryObj->id ) )
                    {
                        $itemArr[] = array( $registryObj->id, $registryObj->attributes, $registryObj->identifier );
                    }
                }
            }
            return $itemArr;
        }

        // Generate the pattern
        $pattern = '/' . strtr( preg_quote( $this->generateAttrStr( $attributes ) ), array( '-' => '.*', '\.' => '.*' ) ) . '/';

        // Makes sure we've seen this ID before
        if ( isset( $this->searchRegistry[$location][$id] )
             && is_array( $this->searchRegistry[$location][$id] ) )
        {
            $itemArr = array();

            // Generate the Identifier
            foreach ( $this->searchRegistry[$location][$id] as $identifier => $dataArr ) // was $this->generateIdentifier( $id, $attributes );
            {
                if ( $this->fetchData( $identifier ) !== false )
                {
                    $itemArr[] = array( $id, $attributes, $identifier );
                }
            }
        }
        else
        {
            $itemArr = array();
            // Finds cache items that fit our description
            if ( isset( $this->searchRegistry[$location] )
                 && is_array( $this->searchRegistry[$location] ) )
            {
                foreach ( $this->searchRegistry[$location] as $id => $arr )
                {
                    foreach ( $arr as $identifier => $registryObj )
                    {
                        $attrStr = $this->generateAttrStr( $registryObj->attributes );
                        if ( preg_match( $pattern, $attrStr ) )
                        {
                            $itemArr[] = array( $registryObj->id, $registryObj->attributes, $registryObj->identifier );
                        }
                    }
                }
            }
        }
        return $itemArr;
    }

    /**
     * Generates a string from the $attributes array.
     *
     * @param array(string=>string) $attributes Attributes describing the data
     * @return string
     */
    protected function generateAttrStr( $attributes = array() )
    {
        ksort( $attributes );
        $attrStr = '';
        foreach ( $attributes as $key => $val )
        {
            $attrStr .= '-' . $key . '=' .$val;
        }
        return $attrStr;
    }

    /**
     * Checks if a given location is valid.
     */
    protected function validateLocation()
    {
        return;
    }

    /**
     * Generates the storage internal identifier from ID and attributes.
     *
     * @param string $id The ID
     * @param array(string=>string) $attributes Attributes describing the data
     * @return string The generated identifier
     */
    public function generateIdentifier( $id, $attributes = null )
    {
       $identifier = strtolower( $this->backendName ) . $this->properties['location'] . $id . ( ( $attributes !== null && !empty( $attributes ) ) ? md5( serialize( $attributes ) ) : '' );
       return urlencode( $identifier );
    }

    /**
     * Calculates the lifetime remaining for a cache object.
     *
     * @param string $identifier The memcache identifier
     * @param bool $dataObject The optional data object for which to calculate the lifetime
     * @return int The remaining lifetime in seconds (0 if no time remaining)
     */
    protected function calcLifetime( $identifier, $dataObject = false )
    {
        $dataObject = is_object( $dataObject ) ? $dataObject : $this->fetchData ( $identifier, true );
        if ( is_object( $dataObject ) )
        {
            return ( ( $lifeTime = ( time() - $dataObject->time ) ) > 0 ) ? $lifeTime : 0;
        }
        else
        {
            return 0;
        }
    }

    /**
     * Registers an identifier to facilitate searching.
     *
     * @param string $id ID for the cache item
     * @param array $attributes Attributes for the cache item
     * @param string $identifier Identifier generated for the cache item
     */
    protected function registerIdentifier( $id = null, $attributes = array(), $identifier = null )
    {
        $identifier = ( $identifier !== null ) ? $identifier : $this->generateIdentifier( $id, $attributes );
        $location = $this->properties['location'];

        $this->fetchSearchRegistry();

        // Makes sure the identifier exists
        if ( !isset( $this->searchRegistry[$location][$id][$identifier] ) )
        {
            // Makes sure the id exists
            if ( !isset( $this->searchRegistry[$location][$id] )
                 || !is_array( $this->searchRegistry[$location][$id] ) )
            {
                $this->searchRegistry[$location][$id] = array();
            }

            $this->searchRegistry[$location][$id][$identifier] = new ezcCacheStorageMemoryRegisterStruct( $id, $attributes, $identifier, $location );
            $this->storeSearchRegistry();
        }
    }

    /**
     * Un-registers a previously registered identifier.
     *
     * @param string $id ID for the cache item
     * @param array $attributes Attributes for the cache item
     * @param string $identifier Identifier generated for the cache item
     * @param bool $delayStore Delays the storing of the updated search registry
     */
    protected function unRegisterIdentifier( $id = null, $attributes = array(), $identifier = null, $delayStore = false )
    {
        $identifier = ( $identifier !== null ) ? $identifier : $this->generateIdentifier( $id, $attributes );
        $location = $this->properties['location'];

        $this->fetchSearchRegistry( !$delayStore );

        if ( $this->searchRegistry === false )
        {
            $this->searchRegistry = array();
        }

        if ( isset( $this->searchRegistry[$location][$id][$identifier] ) )
        {
            unset( $this->searchRegistry[$location][$id][$identifier], $this->registry[$location][$id][$identifier] );
            if ( $delayStore === false )
            {
                $this->storeSearchRegistry();
            }
        }
    }

    /**
     * Fetches the search registry from the backend or creates it if empty.
     *
     * @param bool $requireFresh To create a new search registry or not
     */
    protected function fetchSearchRegistry( $requireFresh = false )
    {
        $location = $this->properties['location'];
        if ( !is_array( $this->searchRegistry ) )
        {
            $this->searchRegistry = array();
        }
        if ( !isset( $this->searchRegistry[$location] )
             || !is_array( $this->searchRegistry[$location] ) )
        {
            $this->searchRegistry[$location] = array();
        }

        // Makes sure the registry exists
        if ( empty( $this->searchRegistry[$location] )
             || $requireFresh === true )
        {
            $this->searchRegistry[$location] = $this->backend->fetch( $this->registryName . '_' . urlencode( $location ) );
        }
    }

    /**
     * Stores the search registry in the backend.
     */
    protected function storeSearchRegistry()
    {
        $location = $this->properties['location'];

        $this->backend->store( $this->registryName . '_' . urlencode( $location ), $this->searchRegistry[$location] );

        $this->searchRegistry[$location] = null;
        $this->fetchSearchRegistry( true );
    }
}
?>