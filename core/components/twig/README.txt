This is not an ambitious integration.

It focuses on chunks and doesn't try to get template inheritance working in the normal Twig way.
It exposes the entire raw MODX object.
It supports Twig inside resources, but isn't hoisting variables from their subcomponents up to it. So you can only use stuff that's actually available in the template and not, for example, loop through stuff that was used in a chunk. Which I think makes sense, but right now I can't think why.

I expect you to use normal placeholders everywhere e.g. `[[++site_url]]`, but later on I will expose these to Twig too.

There is no ContentBlocks integration yet.

This Twig implementation is compatible with Fenom because it passes Twig first and Fenom runs at the last possible moment over the entire template in a single pass. While they have a speed advantage, I have the advantage of getting hold of all parameters passed to chunks and to chunks from snippets.

Twig is a lot more forgiving than Fenom and will cope with missing tags and an awful lot of JSON and JavaScript in the template.


------------------------------------------------------

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
