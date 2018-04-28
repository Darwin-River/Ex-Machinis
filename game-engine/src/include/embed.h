/** @file      embed.h
 *  @brief     Embed Forth Virtual Machine Library Interface
 *  @copyright Richard James Howe (2017,2018)
 *  @license   MIT */

#ifndef EMBED_H
#define EMBED_H

#include <stdio.h>
#include <stdint.h>

#ifdef __cplusplus
extern "C" {
#endif

struct forth_t;                 /**< Forth Virtual Machine State */
typedef struct forth_t forth_t; /**< Forth Virtual Machine State Type Define */

void     embed_die(const char *fmt, ...); /**< fprintf to stderr and die */
FILE    *embed_fopen_or_die(const char *file, const char *mode); /** die on fopen failure */
forth_t *embed_new(void);        /**< make a new Forth VM */
forth_t *embed_copy(forth_t *f); /**< Copy existing instance of a Forth VM */
void     embed_free(forth_t *f); /**< Delete a Forth VM */
int      embed_forth(forth_t *h, FILE *in, FILE *out, const char *block); /**< Run! */
int      embed_load(forth_t *h, const char *name); /**< Load VM image off disk */
int      embed_save(forth_t *h, const char *name); /**< Save VM image to disk */

#ifdef USE_CUSTOM_EMBED	
forth_t* embed_load_from_memory(unsigned char* bytes, const size_t size);
unsigned char* embed_save_into_memory(forth_t *h, size_t *size);
int embed_eval(forth_t *h, const char *in, const size_t in_size, char *out, const size_t out_size) ;
#endif

#ifdef __cplusplus
}
#endif
#endif /* EMBED_H */
