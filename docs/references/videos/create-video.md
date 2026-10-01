Create a video resource from an existing file in a storage bucket. The source file must be a video or audio file. Creating a video only stores the document; it does not download or probe the file. Call create-rendition or create-timeline to process the source — each job downloads the file, probes metadata, extracts embedded subtitles once, and deletes its working copy when finished.

An optional `name` defaults to the source file name. Uploaded subtitle files override auto-extracted tracks for the same language once extraction has run as part of the first rendition or timeline job.
