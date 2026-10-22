/* Writes the engine's built-in FORTH image (embed_image.c) to stdout. The
 * game engine loads it from FORTH_IMAGE_PATH (bin/eforth.blk). Images built
 * from embed/ don't match the engine's VM. */
#include <stdint.h>
#include <stdio.h>

extern const uint8_t embed_default_block[];
extern const size_t embed_default_block_size;

int main(void)
{
    return fwrite(embed_default_block, 1, embed_default_block_size, stdout) != embed_default_block_size;
}
