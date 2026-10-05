





Internet Engineering Task Force (IETF)                        J. Klensin
Request for Comments: 6152
STD: 71                                                         N. Freed
Obsoletes: 1652                                                   Oracle
Category: Standards Track                                        M. Rose
ISSN: 2070-1721                             Dover Beach Consulting, Inc.
                                                         D. Crocker, Ed.
                                             Brandenburg InternetWorking
                                                              March 2011


            SMTP Service Extension for 8-bit MIME Transport

Abstract

   This memo defines an extension to the SMTP service whereby an SMTP
   content body consisting of text containing octets outside of the
   US-ASCII octet range (hex 00-7F) may be relayed using SMTP.

Status of This Memo

   This is an Internet Standards Track document.

   This document is a product of the Internet Engineering Task Force
   (IETF).  It represents the consensus of the IETF community.  It has
   received public review and has been approved for publication by the
   Internet Engineering Steering Group (IESG).  Further information on
   Internet Standards is available in Section 2 of RFC 5741.

   Information about the current status of this document, any errata,
   and how to provide feedback on it may be obtained at
   http://www.rfc-editor.org/info/rfc6152.

Copyright Notice

   Copyright (c) 2011 IETF Trust and the persons identified as the
   document authors.  All rights reserved.

   This document is subject to BCP 78 and the IETF Trust's Legal
   Provisions Relating to IETF Documents
   (http://trustee.ietf.org/license-info) in effect on the date of
   publication of this document.  Please review these documents
   carefully, as they describe your rights and restrictions with respect
   to this document.  Code Components extracted from this document must
   include Simplified BSD License text as described in Section 4.e of
   the Trust Legal Provisions and are provided without warranty as
   described in the Simplified BSD License.




Klensin, et al.              Standards Track                    [Page 1]

RFC 6152              SMTP Extension for 8-bit MIME           March 2011


1.  Introduction

   Although SMTP is widely and robustly deployed, various extensions
   have been requested by parts of the Internet community.  In
   particular, a significant portion of the Internet community wishes to
   exchange messages in which the content body consists of a MIME
   message [RFC2045][RFC2046][RFC5322] containing arbitrary octet-
   aligned material.  This memo uses the mechanism described in the SMTP
   specification [RFC5321] to define an extension to the SMTP service
   whereby such contents may be exchanged.  Note that this extension
   does NOT eliminate the possibility of an SMTP server limiting line
   length; servers are free to implement this extension but nevertheless
   set a line length limit no lower than 1000 octets.  Given that this
   restriction still applies, this extension does NOT provide a means
   for transferring unencoded binary via SMTP.

2.  Framework for the 8-bit MIME Transport Extension

   The 8-bit MIME transport extension is laid out as follows:

   1.  the name of the SMTP service extension defined here is
       8bit-MIMEtransport;

   2.  the EHLO keyword value associated with the extension is 8BITMIME;

   3.  no parameter is used with the 8BITMIME EHLO keyword;

   4.  one optional parameter using the keyword BODY is added to the
       MAIL command.  The value associated with this parameter is a
       keyword indicating whether a 7-bit message (in strict compliance
       with [RFC5321]) or a MIME message (in strict compliance with
       [RFC2046] and [RFC2045]) with arbitrary octet content is being
       sent.  The syntax of the value is as follows, using the ABNF
       notation of [RFC5234]:

       body-value = "7BIT" / "8BITMIME"

   5.  no additional SMTP verbs are defined by this extension; and

   6.  the next section specifies how support for the extension affects
       the behavior of a server and client SMTP.

3.  The 8bit-MIMEtransport Service Extension

   When a client SMTP wishes to submit (using the MAIL command) a
   content body consisting of a MIME message containing arbitrary lines
   of octet-aligned material, it first issues the EHLO command to the
   server SMTP.  If the server SMTP responds with code 250 to the EHLO



Klensin, et al.              Standards Track                    [Page 2]

RFC 6152              SMTP Extension for 8-bit MIME           March 2011


   command, and the response includes the EHLO keyword value 8BITMIME,
   then the server SMTP is indicating that it supports the extended MAIL
   command and will accept MIME messages containing arbitrary octet-
   aligned material.

   The extended MAIL command is issued by a client SMTP when it wishes
   to transmit a content body consisting of a MIME message containing
   arbitrary lines of octet-aligned material.  The syntax for this
   command is identical to the MAIL command in RFC 5321, except that a
   BODY parameter must appear after the address.  Only one BODY
   parameter may be used in a single MAIL command.

   The complete syntax of this extended command is defined in RFC 5321.
   The esmtp-keyword is BODY, and the syntax for esmtp-value is given by
   the syntax for body-value shown above.

   The value associated with the BODY parameter indicates whether the
   content body that will be passed using the DATA command consists of a
   MIME message containing some arbitrary octet-aligned material
   ("8BITMIME") or is encoded entirely in accordance with RFC 5321
   ("7BIT").

   A server that supports the 8-bit MIME transport service extension
   shall preserve all bits in each octet passed using the DATA command.
   Naturally, the usual SMTP data-stuffing algorithm applies, so that a
   content that contains the five-character sequence of
   <CR> <LF> <DOT> <CR> <LF>
   or a content that begins with the three-character sequence of
   <DOT> <CR> <LF>
   does not prematurely terminate the transfer of the content.  Further,
   it should be noted that the CR-LF pair immediately preceding the
   final dot is considered part of the content.  Finally, although the
   content body contains arbitrary lines of octet-aligned material, the
   length of each line (number of octets between two CR-LF pairs) is
   still subject to SMTP server line length restrictions (which can
   allow as few as 1000 octets, inclusive of the CR-LF pair, on a single
   line).  This restriction means that this extension provides the
   necessary facilities for transferring a MIME object with the 8BIT
   content-transfer-encoding, it DOES NOT provide a means of
   transferring an object with the BINARY content-transfer-encoding.

   Once a server SMTP supporting the 8bit-MIMEtransport service
   extension accepts a content body containing octets with the high-
   order (8th) bit set, the server SMTP must deliver or relay the
   content in such a way as to preserve all bits in each octet.






Klensin, et al.              Standards Track                    [Page 3]

RFC 6152              SMTP Extension for 8-bit MIME           March 2011


   If a server SMTP does not support the 8-bit MIME transport extension
   (either by not responding with code 250 to the EHLO command, or by
   not including the EHLO keyword value 8BITMIME in its response), then
   the client SMTP must not, under any circumstances, attempt to
   transfer a content that contains characters outside of the US-ASCII
   octet range (hex 00-7F).

   A client SMTP has two options in this case: first, it may implement a
   gateway transformation to convert the message into valid 7-bit MIME,
   or second, it may treat the barrier to 8-bit as a permanent error and
   handle it in the usual manner for delivery failures.  The specifics
   of the transformation from 8-bit MIME to 7-bit MIME are not described
   by this RFC; the conversion is nevertheless constrained in the
   following ways:

   1.  it must cause no loss of information; MIME transport encodings
       must be employed as needed to insure this is the case, and

   2.  the resulting message must be valid 7-bit MIME.

4.  Usage Example

   The following dialogue illustrates the use of the 8bit-MIMEtransport
   service extension:

   S: <wait for connection on TCP port 25>
   C: <open connection to server>
   S: 220 dbc.mtview.ca.us SMTP service ready
   C: EHLO ymir.claremont.edu
   S: 250-dbc.mtview.ca.us says hello
   S: 250 8BITMIME
   C: MAIL FROM:<ned@ymir.claremont.edu> BODY=8BITMIME
   S: 250 <ned@ymir.claremont.edu>... Sender and 8BITMIME ok
   C: RCPT TO:<mrose@dbc.mtview.ca.us>
   S: 250 <mrose@dbc.mtview.ca.us>... Recipient ok
   C: DATA
   S: 354 Send 8BITMIME message, ending in CRLF.CRLF.
    ...
   C: .
   S: 250 OK
   C: QUIT
   S: 250 Goodbye









Klensin, et al.              Standards Track                    [Page 4]

RFC 6152              SMTP Extension for 8-bit MIME           March 2011


5.  Security Considerations

   This RFC does not discuss security issues and is not believed to
   raise any security issues not already endemic in electronic mail and
   present in fully conforming implementations of RFC 5321, including
   attacks facilitated by the presence of an option negotiation
   mechanism.  Since MIME semantics are transport-neutral, the 8BITMIME
   option provides no more added capability to disseminate malware than
   is provided by unextended 7-bit SMTP.

6.  IANA Considerations

6.1.  SMTP Service Extension Registration

   This document defines an SMTP and Submit service extension.  IANA has
   updated the 8BITMIME entry in the SMTP Service Extensions registry,
   as follows:

   Keyword:   8BITMIME

   Description:   SMTP and Submit transport of 8-bit MIME content

   Reference:   [RFC6152]

   Parameters:   See Section 2 in this specification.

7.  Acknowledgements

   E. Stefferud was an original author.  This version of the
   specification was produced by the YAM working group.

   Original acknowledgements:   This document represents a synthesis of
      the ideas of many people and reactions to the ideas and proposals
      of others.  Randall Atkinson, Craig Everhart, Risto Kankkunen, and
      Greg Vaudreuil contributed ideas and text sufficient to be
      considered co-authors.  Other important suggestions, text, or
      encouragement came from Harald Alvestrand, Jim Conklin,
      Mark Crispin, Frank da Cruz, Olafur Gudmundsson, Per Hedeland,
      Christian Huitma, Neil Katin, Eliot Lear, Harold A. Miller,
      Keith Moore, Dan Oscarsson, Julian Onions, Neil Rickert,
      John Wagner, Rayan Zachariassen, and the contributions of the
      entire IETF SMTP Working Group.  Of course, none of the
      individuals are necessarily responsible for the combination of
      ideas represented here.  Indeed, in some cases, the response to a
      particular criticism was to accept the problem identification but
      to include an entirely different solution from the one originally
      proposed.




Klensin, et al.              Standards Track                    [Page 5]

RFC 6152              SMTP Extension for 8-bit MIME           March 2011


8.  Normative References

   [RFC2045]  Freed, N. and N. Borenstein, "Multipurpose Internet Mail
              Extensions (MIME) Part One: Format of Internet Message
              Bodies", RFC 2045, November 1996.

   [RFC2046]  Freed, N. and N. Borenstein, "Multipurpose Internet Mail
              Extensions (MIME) Part Two: Media Types", RFC 2046,
              November 1996.

   [RFC5234]  Crocker, D. and P. Overell, "Augmented BNF for Syntax
              Specifications: ABNF", STD 68, RFC 5234, January 2008.

   [RFC5321]  Klensin, J., "Simple Mail Transfer Protocol", RFC 5321,
              October 2008.

   [RFC5322]  Resnick, P., Ed., "Internet Message Format", RFC 5322,
              October 2008.

































Klensin, et al.              Standards Track                    [Page 6]

RFC 6152              SMTP Extension for 8-bit MIME           March 2011


Authors' Addresses

   John C. Klensin
   1770 Massachusetts Ave, Ste. 322
   Cambridge, MA  02140
   USA

   Phone: +1 617 245 1457
   EMail: john+ietf@jck.com


   Ned Freed
   Oracle
   800 Royal Oaks
   Monrovia, CA  91016-6347
   USA

   EMail: ned.freed@mrochek.com


   M. Rose
   Dover Beach Consulting, Inc.
   POB 255268
   Sacramento, CA  95865-5268
   USA

   Phone: +1 916 538 2535
   EMail: mrose17@gmail.com


   D. Crocker (editor)
   Brandenburg InternetWorking
   675 Spruce Dr.
   Sunnyvale, CA
   USA

   Phone: +1 408 246 8253
   EMail: dcrocker@bbiw.net
   URI:   http://bbiw.net












Klensin, et al.              Standards Track                    [Page 7]

