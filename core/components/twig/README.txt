Add a Namespace to load the bootstrap.php file
Name: twig
Path: {core_path}components/twig/

For chunk parameters, modParser processElementTags has a routine to build up $tagMap
Involves $this->processTag($tag, $processUncacheable);
and \MODX\Revolution\modTag::getProperties() which adds them to the MODX $modx->_properties array

There's something about Twig running before these values have been added to $modx->_properties

Maybe this isn't possible to solve which is why pdoTools/Fenom haven't done it.

modX::getChunk() has $chunk= $this->parser->getElement(modChunk::class, $chunkName);
What about if we subclass modChunk to provide something that passes the data in when calling processElementTags?
