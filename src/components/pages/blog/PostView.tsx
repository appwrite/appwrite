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
  getPrimaryPostCategorySlug,
  getPostCategoryLabel,
  getRelatedBlogPosts,
  resolveBlogAuthors,
} from '@/lib/blog/content'
import type { BlogAuthor, BlogCategory, BlogPost, BlogPostMeta } from '@/lib/blog/types'
import {
  BLOG_CATEGORY_TITLE_CLASS,
  BLOG_PAGE_DESCRIPTION_CLASS,
  BLOG_PAGE_TITLE_CLASS,
} from '@/lib/blog/prose-typography'
import { cn } from '@/lib/utils'
import { BlogArticleHeader } from './BlogArticleHeader'
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
  const categorySlug = getPrimaryPostCategorySlug(post)
  const categoryLabel = getPostCategoryLabel(post)

  return (
    <div className="relative bg-background">
      <section className="border-b border-border py-10 sm:py-14">
        <div className="@container mx-auto w-full max-w-7xl px-4 sm:px-6">
          <Breadcrumb>
            <BreadcrumbList>
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
            </BreadcrumbList>
          </Breadcrumb>

          <div className="mt-8 grid items-start gap-8 overflow-visible @[900px]:grid-cols-[minmax(0,56rem)_1px_minmax(192px,208px)] @[900px]:gap-x-12 @[1080px]:gap-x-16">
            <article className="min-w-0">
              <BlogArticleHeader
                slug={post.slug}
                title={post.title}
                description={post.description}
                authors={authors}
                date={post.date}
                timeToRead={post.timeToRead}
                lastUpdated={post.lastUpdated}
              />

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
            <BreadcrumbList>
              <BreadcrumbItem>
                <BreadcrumbLink asChild>
                  <Link to="/blog" className="cursor-pointer">
                    Blog
                  </Link>
                </BreadcrumbLink>
              </BreadcrumbItem>
              <BreadcrumbSeparator />
              <BreadcrumbItem className="min-w-0">
                <BreadcrumbPage className="truncate">
                  {category.name}
                </BreadcrumbPage>
              </BreadcrumbItem>
            </BreadcrumbList>
          </Breadcrumb>

          <header className="mt-8 max-w-4xl border-y border-border py-6">
            <h1 className={BLOG_CATEGORY_TITLE_CLASS}>{category.name}</h1>
            <p className={BLOG_PAGE_DESCRIPTION_CLASS}>
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
            <BreadcrumbList>
              <BreadcrumbItem>
                <BreadcrumbLink asChild>
                  <Link to="/blog" className="cursor-pointer">
                    Blog
                  </Link>
                </BreadcrumbLink>
              </BreadcrumbItem>
              <BreadcrumbSeparator />
              <BreadcrumbItem className="min-w-0">
                <BreadcrumbPage className="truncate font-aeonik-pro">
                  {author.name}
                </BreadcrumbPage>
              </BreadcrumbItem>
            </BreadcrumbList>
          </Breadcrumb>

          <header className="mt-8 flex flex-col items-start gap-4 border-y border-border py-8 sm:flex-row sm:items-center">
            <BlogAvatarPlaceholder name={author.name} className="size-20 text-[18px]" />
            <div>
              <h1 className={BLOG_PAGE_TITLE_CLASS}>{author.name}</h1>
              {author.role ? (
                <p className="mt-1 text-[13px] text-muted-foreground">{author.role}</p>
              ) : null}
              {author.bio ? (
                <p className={cn(BLOG_PAGE_DESCRIPTION_CLASS, 'mt-3 font-normal')}>
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
