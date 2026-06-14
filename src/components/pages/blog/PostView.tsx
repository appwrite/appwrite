import { Link } from '@tanstack/react-router'
import { Github, Linkedin } from 'lucide-react'
import {
  MarketingCtaSection,
} from '@/components/pages/marketing/MarketingSections'
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from '@/components/ui/breadcrumb'
import { Button } from '@/components/ui/button'
import { formatDate } from '@/lib/date-utils'
import {
  getAllBlogAuthors,
  getRelatedBlogPosts,
  normalizeCategory,
  resolveBlogAuthors,
} from '@/lib/blog/content'
import type { BlogAuthor, BlogCategory, BlogPost, BlogPostMeta } from '@/lib/blog/types'
import {
  BLOG_BREADCRUMB_LIST_CLASS,
  BLOG_BREADCRUMB_PAGE_CLASS,
  BLOG_PAGE_TITLE_CLASS,
  BLOG_PAGE_DESCRIPTION_CLASS,
  BLOG_SECTION_TITLE_CLASS,
} from '@/lib/blog/prose-typography'
import { cn } from '@/lib/utils'
import { BlogAvatarPlaceholder, BlogCoverPlaceholder } from './BlogCoverPlaceholder'
import { BlogFaqSection } from './BlogFaqSection'
import { BlogMarkdown } from './BlogMarkdown'
import { BlogPostCard } from './BlogPostCard'
import { BlogToc } from './BlogToc'

type PostViewProps = {
  post: BlogPost
}

export function PostView({ post }: PostViewProps) {
  const authors = resolveBlogAuthors(post.author)
  const allAuthors = getAllBlogAuthors()
  const relatedPosts = getRelatedBlogPosts(post.slug)
  const categorySlug = normalizeCategory(post.category)
  const categoryLabel = post.category
    .replace(/-/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase())

  return (
    <div className="relative bg-background">
      <section className="border-b border-border py-10 sm:py-14">
        <div className="@container mx-auto w-full max-w-7xl px-4 sm:px-6">
          <Breadcrumb>
            <BreadcrumbList className={BLOG_BREADCRUMB_LIST_CLASS}>
              <BreadcrumbItem>
                <BreadcrumbLink asChild>
                  <Link to="/blog" className="cursor-pointer">
                    Blog
                  </Link>
                </BreadcrumbLink>
              </BreadcrumbItem>
              <BreadcrumbSeparator />
              <BreadcrumbItem className="min-w-0">
                <BreadcrumbLink asChild>
                  <Link
                    to="/blog/category/$category"
                    params={{ category: categorySlug }}
                    className="cursor-pointer"
                  >
                    {categoryLabel}
                  </Link>
                </BreadcrumbLink>
              </BreadcrumbItem>
              <BreadcrumbSeparator />
              <BreadcrumbItem className="min-w-0">
                <BreadcrumbPage className={cn(BLOG_BREADCRUMB_PAGE_CLASS, 'truncate')}>
                  {post.title}
                </BreadcrumbPage>
              </BreadcrumbItem>
            </BreadcrumbList>
          </Breadcrumb>

          <div className="mt-8 grid items-start gap-8 overflow-visible @[900px]:grid-cols-[minmax(0,52rem)_1px_minmax(192px,208px)] @[900px]:gap-x-12 @[1080px]:gap-x-16">
            <article className="min-w-0">
              <header className="border-y border-border py-4">
                <h1 className={BLOG_PAGE_TITLE_CLASS}>{post.title}</h1>
                <p className={cn(BLOG_PAGE_DESCRIPTION_CLASS, 'mt-4')}>
                  {post.description}
                </p>

                <div className="mt-6 flex flex-wrap items-center gap-4">
                  {authors.map((author) => (
                    <Link
                      key={author.slug}
                      to="/blog/author/$author"
                      params={{ author: author.slug }}
                      className="inline-flex items-center gap-2"
                    >
                      <BlogAvatarPlaceholder name={author.name} />
                      <div className="min-w-0">
                        <p className="text-[13px] font-medium text-foreground">
                          {author.name}
                        </p>
                        {author.role ? (
                          <p className="text-[12px] text-muted-foreground">{author.role}</p>
                        ) : null}
                      </div>
                    </Link>
                  ))}
                </div>

                <div className="mt-4 flex flex-wrap items-center gap-3 text-[11px] uppercase tracking-[0.16em] text-muted-foreground">
                  <time dateTime={post.date}>{formatDate(post.date)}</time>
                  {post.timeToRead > 0 ? <span>{post.timeToRead} min read</span> : null}
                  {post.lastUpdated !== post.date ? (
                    <span>Updated {formatDate(post.lastUpdated)}</span>
                  ) : null}
                </div>
              </header>

              <div className="mt-8">
                <BlogCoverPlaceholder title={post.title} />
              </div>

              <div className="mt-8 min-w-0 overflow-x-hidden">
                <BlogMarkdown content={post.content} />
              </div>

              {post.faqs?.length ? <BlogFaqSection faqs={post.faqs} /> : null}
            </article>

            <div
              aria-hidden
              className="hidden w-px self-stretch bg-border @[900px]:block"
            />
            <BlogToc items={post.toc} />
          </div>
        </div>
      </section>

      {relatedPosts.length > 0 ? (
        <section className="border-b border-border py-12 sm:py-16">
          <div className="mx-auto max-w-7xl px-4 sm:px-6">
            <h2 className="font-aeonik-pro text-[22px] font-normal text-foreground">Read next</h2>
            <div className="mt-8 grid grid-cols-1 gap-10 md:grid-cols-2 xl:grid-cols-3">
              {relatedPosts.map((relatedPost) => (
                <BlogPostCard
                  key={relatedPost.slug}
                  post={relatedPost}
                  authors={allAuthors}
                />
              ))}
            </div>
          </div>
        </section>
      ) : null}

      <MarketingCtaSection title="Ready to build?">
        <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
          <Link to="/sign-up" search={{ redirect: '/' }}>
            Get started
          </Link>
        </Button>
        <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
          <Link to="/pricing">View pricing</Link>
        </Button>
      </MarketingCtaSection>
    </div>
  )
}

type CategoryViewProps = {
  category: BlogCategory
  posts: BlogPostMeta[]
  authors: BlogAuthor[]
}

export function CategoryView({ category, posts, authors }: CategoryViewProps) {
  return (
    <div className="relative overflow-x-hidden bg-background">
      <section className="border-b border-border py-10 sm:py-14">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <Breadcrumb>
            <BreadcrumbList className={BLOG_BREADCRUMB_LIST_CLASS}>
              <BreadcrumbItem>
                <BreadcrumbLink asChild>
                  <Link to="/blog" className="cursor-pointer">
                    Blog
                  </Link>
                </BreadcrumbLink>
              </BreadcrumbItem>
              <BreadcrumbSeparator />
              <BreadcrumbItem className="min-w-0">
                <BreadcrumbPage className={cn(BLOG_BREADCRUMB_PAGE_CLASS, 'truncate')}>
                  {category.name}
                </BreadcrumbPage>
              </BreadcrumbItem>
            </BreadcrumbList>
          </Breadcrumb>

          <header className="mt-8 max-w-3xl border-y border-border py-6">
            <h1 className={BLOG_SECTION_TITLE_CLASS}>{category.name}</h1>
            <p className={cn(BLOG_PAGE_DESCRIPTION_CLASS, 'mt-4')}>
              {category.description}
            </p>
          </header>

          <div className="mt-10 grid grid-cols-1 gap-10 md:grid-cols-2 xl:grid-cols-3">
            {posts.map((post) => (
              <BlogPostCard key={post.slug} post={post} authors={authors} />
            ))}
          </div>
        </div>
      </section>

      <MarketingCtaSection title="Ready to build?">
        <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
          <Link to="/sign-up" search={{ redirect: '/' }}>
            Get started
          </Link>
        </Button>
        <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
          <Link to="/pricing">View pricing</Link>
        </Button>
      </MarketingCtaSection>
    </div>
  )
}

type AuthorViewProps = {
  author: BlogAuthor
  posts: BlogPostMeta[]
  authors: BlogAuthor[]
}

export function AuthorView({ author, posts, authors }: AuthorViewProps) {
  return (
    <div className="relative overflow-x-hidden bg-background">
      <section className="border-b border-border py-10 sm:py-14">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <Breadcrumb>
            <BreadcrumbList className={BLOG_BREADCRUMB_LIST_CLASS}>
              <BreadcrumbItem>
                <BreadcrumbLink asChild>
                  <Link to="/blog" className="cursor-pointer">
                    Blog
                  </Link>
                </BreadcrumbLink>
              </BreadcrumbItem>
              <BreadcrumbSeparator />
              <BreadcrumbItem className="min-w-0">
                <BreadcrumbPage className={cn(BLOG_BREADCRUMB_PAGE_CLASS, 'truncate')}>
                  {author.name}
                </BreadcrumbPage>
              </BreadcrumbItem>
            </BreadcrumbList>
          </Breadcrumb>

          <header className="mt-8 flex flex-col items-start gap-4 border-y border-border py-8 sm:flex-row sm:items-center">
            <BlogAvatarPlaceholder name={author.name} className="size-20 text-[18px]" />
            <div>
              <h1 className={BLOG_SECTION_TITLE_CLASS}>{author.name}</h1>
              {author.role ? (
                <p className={cn(BLOG_PAGE_DESCRIPTION_CLASS, 'mt-1')}>{author.role}</p>
              ) : null}
              {author.bio ? (
                <p className={cn(BLOG_PAGE_DESCRIPTION_CLASS, 'mt-3 max-w-2xl font-normal')}>
                  {author.bio}
                </p>
              ) : null}
              <div className="mt-4 flex items-center gap-2">
                {author.github ? (
                  <a
                    href={author.github}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex size-9 items-center justify-center rounded-md border border-border text-muted-foreground hover:text-foreground"
                    aria-label="Author GitHub"
                  >
                    <Github className="h-4 w-4" />
                  </a>
                ) : null}
                {author.linkedin ? (
                  <a
                    href={author.linkedin}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex size-9 items-center justify-center rounded-md border border-border text-muted-foreground hover:text-foreground"
                    aria-label="Author LinkedIn"
                  >
                    <Linkedin className="h-4 w-4" />
                  </a>
                ) : null}
              </div>
            </div>
          </header>

          <h2 className="mt-10 font-aeonik-pro text-[22px] font-normal text-foreground">Articles</h2>
          <div className="mt-8 grid grid-cols-1 gap-10 md:grid-cols-2 xl:grid-cols-3">
            {posts.map((post) => (
              <BlogPostCard key={post.slug} post={post} authors={authors} />
            ))}
          </div>
        </div>
      </section>

      <MarketingCtaSection title="Ready to build?">
        <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
          <Link to="/sign-up" search={{ redirect: '/' }}>
            Get started
          </Link>
        </Button>
        <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
          <Link to="/pricing">View pricing</Link>
        </Button>
      </MarketingCtaSection>
    </div>
  )
}
