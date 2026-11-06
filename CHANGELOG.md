# Changelog

All notable changes to Luster are documented in this file. For the full
feature overview, see the [README](README.md).


## A Note on Updating

Each release includes a packaged ZIP file and a matching checksum file.
Luster's admin panel can check for these and apply updates automatically —
it will never touch your `config/app.php` or `storage/` folder, and
`public/uploads/` is always left alone too. It also cleans up old files that
aren't part of the new release anymore, and keeps your `vendor/` folder (the
bundled dependencies) up to date automatically. The one exception is
`public/.htaccess`: if you've customized it, Luster leaves it alone and saves
you a copy of the new default to look over instead of overwriting your
changes. If a release is missing one of the files an update needs, the admin
panel will let you know that a manual update is needed and link you to the
release page.


### [Unreleased]

**After updating:** go to **Settings → System & Updates** and click **Run
migrations** once. This update adds one small thing to your database, so Luster
can stop a fan's update request for you to look at.

**Added:**
- **A fan's update request can be stopped for you to check.** If something doesn't
  add up, like a request sent before its member even joined, the request stays in
  the queue marked **Needs review**, with the reason, and there's no Approve button
  on it. Look over the member, make any changes you still want by hand, and reject
  the request to clear it. Rejecting only removes the request. If a request was
  already in your queue when you update and its member joined around the same time
  or later, it'll stop like this too, because Luster can't tell it's the same person.

**Fixed:**
- **An email problem can't freeze your admin panel anymore.** When a new version of
  Luster came out and your email settings weren't working, every admin page tried to
  email you about it and sat there waiting for the email to fail. It could look like
  your login was broken or your database was down. Now a failed update email waits a
  day before trying again, so it slows down one page a day instead of all of them.
  This doesn't fix the email settings themselves, so if you haven't been getting
  emails, check **Settings → SMTP & Email** and send yourself a test.
- **Luster doesn't wait as long on a mail server that isn't answering.** One email could
  hold a page up for 5 minutes or more. Most waits now use 15 seconds. In
  testing, Luster gave up on a mail server it couldn't reach after 15 seconds, and on one
  that stopped answering partway through after up to a minute. This helps anywhere Luster
  sends an email while someone waits, like your join and contact forms.
- **Leaving the SMTP Port box empty doesn't save it as port 1 anymore.** Port 1 isn't a
  standard SMTP port, so your emails would most likely fail, and nothing on the page told
  you why. Now an empty box uses the usual port for your encryption setting: 465 for SMTPS and 587
  otherwise. If your Port already says 1, change it to the one your email provider lists.
- **Fans' email addresses don't go on public pages anymore.** The `{fan_email}` token
  printed a fan's address on your member list, even if they'd chosen to hide it, and
  importing from Enthusiast or Listing Admin could put that token in your template
  without you asking. Emails are only for you now: you can still see them in your
  admin panel and send fans email, and your email templates still use `{fan_email}`
  like before. In a Members template it just shows nothing, so you can take it out
  whenever you like. If you were showing emails on purpose, this is what changes for
  you. Addresses that were already public may still be saved by search engines, and
  Luster can't undo that. New fans are also saved with their email marked as hidden,
  unless an import brings over a choice they really made.
- **Importing from Enthusiast can't mix up your listings anymore.** A listing now only
  goes to the one with the same web address, or to itself if Luster shares Enthusiast's
  database. Before, it could land on an unrelated listing that happened to have the
  same number, make a second listing on one member table, add its fans to another
  listing's list, or change two listings at once if they shared an address. It could
  even copy from a table that isn't a member table at all, like Luster's own admin
  logins, and add it as fans, if an old listing named that table. Anything Luster can't
  place safely is now left alone and listed at the top of the report, with the reason
  and what to do about it. That includes a listing whose member table another listing
  here also uses, because importing into one would change the other.
- **"You can now safely delete the old Enthusiast database" is gone.** It could show up
  when only your fans had come across, with your settings and templates still back
  there, and even after fans were copied from the wrong table. Luster can't know the
  old database is safe to throw away, so now it just asks you to check your listings
  first. If Luster reads your member tables right where they are, it tells you to keep
  that database.
- **Luster checks where a listing's fans really are before copying them.** Enthusiast
  saves a database for each listing. If that's not the one you picked, Luster won't
  copy a table that just happens to have the same name. This includes every `.sql`
  upload, restored backups and most imports from another server, because those still
  name the old place. The Preview lists those listings with a box to tick if the fans
  really are there. The tick only covers the listings it shows, for that one run. If
  anything changes in between, Luster sends you back to look again.
- **Importing again works when a listing has gained a field.** New fans used to be
  refused, and on Overwrite every update was too. Now new fields get their own column,
  and Skip brings fans in without that one answer and tells you.
- **Overwrite keeps the fields you've set up.** It used to replace a listing's fields
  with the old list, so a dropdown turned back into a text box and lost its choices, and
  a field you added in Luster vanished. Now your labels, choices and fields stay, and
  only what the old script really has can change them. A field missing from the old
  table can't blank an answer either.
- **Every imported listing gets a member table.** A listing without one breaks its
  public member list. Importing only Owned Listings left you with exactly that, so now
  those listings get an empty table until their fans come over. If you restore an
  export by hand afterward, one without `DROP TABLE IF EXISTS` can collide with the
  empty table.
- **Deleting a listing can't take another listing's fans with it.** If two listings
  share a member table, the table stays until the last one goes. If a listing's table
  name is really one of Luster's or Enthusiast's own, or Luster can't check who else
  uses it, nothing gets deleted and you're told why.
- **Importing with extra fields works on strict MySQL servers.** The member table was
  refused, so those fans didn't come across. This fixes the Listing Admin import too.
- **A field name Luster can't use gets a note.** Its answers used to be left behind
  without a word. A field listed twice doesn't stop the whole member table anymore, and
  `$$fan_url_plain$$` and `$$fan_email_plain$$` don't print as plain text.
- **Fans waiting for approval stay waiting** when an import source tracks that with an
  `approved` column. They used to arrive approved, and showed up publicly.
- **What this can't undo:** if you've already imported from Enthusiast more than once,
  check your listings. Luster has no way to spot settings that landed on the wrong
  listing, two listings overwritten from one, fans copied from the wrong table, or
  waiting fans who were approved by mistake. Tables left unused by an earlier import
  are harmless, and you can drop them once you're happy.
- **Every Approve button follows the same rules now.** Approve, Approve All, bulk
  Approve and the Approve All on the Members overview each did things their own way,
  so the same queue could turn out differently depending on which one you clicked.
  Now requests always go oldest first, so the newest one wins, one bad request never
  stops the rest, and the message tells you what happened to each.
- **A bad update request can't damage a member anymore.** A blank email or name, or
  a `javascript:` website address, used to be saved just as it was. Now it isn't
  applied. It stays in the queue with a message saying what's wrong, so you can
  reject it.
- **A request can only change what it's allowed to.** Only a member's name, email,
  website, country, show-website setting and extra fields can come from a request.
- **A request that doesn't change anything doesn't email the fan.** It's just removed.
- **Changing only the capitals of your own email works.** `Fan@example.com` for
  `fan@example.com` used to count as someone else's address, and the whole request
  was thrown away, along with anything else in it.
- **An email address too long for your member table isn't cut short.** On many older
  tables, a long address was saved with the end missing and the fan could no longer
  be found by it. Now the request stays in the queue and tells you why.
- **Saving a new email address takes everything with it.** The public update form
  left waiting requests, link checks and reset links behind under the old address.
- **An old reset link can't work on the wrong person.** When a fan's email changes,
  any reset link for the old or new address stops working straight away, even if the
  save goes wrong partway. The same goes for someone new getting an address, by joining,
  being added or changing to it: any old reset or Collective Update link for that address
  stops working. If a save fails, the worst it costs a fan is asking for a new link.
- **A new email and a new password go in one save now.** If a fan changes both on the
  same form and the save fails, Luster tells them which password to use. Usually nothing
  changed, so their old password still works and they can just try again. If Luster can't
  be sure, mostly on older MyISAM tables that can't undo a save, it tells them either
  password might be the right one.
- **If saving fails, Luster says what really happened.** Usually nothing changed and
  you can just try again. If Luster can't be sure, mostly on older MyISAM tables that
  can't undo a save, it tells you, sends no email, and stops the requests for that
  member so you can check.
- **Approve All stops if the database has a serious problem.** It used to carry on and
  could get confused about what was already done. Now it stops, tells you, and leaves
  the rest in the queue.
- **Extra spaces around a requested email can't lock a fan out.** They used to get saved,
  and then the fan couldn't sign in with their own address.
- **Long answers in older tables aren't refused by mistake.** Luster counted accented
  letters as two bytes even in tables that store them as one, so some answers that
  would have fit were called too long. Tables using a rarer character set, like UTF-16,
  still get a cautious count, so a long answer that would just fit can still be refused.
- **Rejecting a request an approval already handled says so,** instead of claiming it
  was rejected.
- **Dropdowns from older scripts work like dropdowns.** Some showed up on your join and
  update forms as a plain text box, with their choices ignored.
- **A dropdown or radio field with no choices can't stop people joining.** If one was
  required, nobody could get past it, because there was nothing to pick. It's a text box
  now until you add choices, and your Fields list says **no choices yet** next to it. If
  a field lost its choices to the old bug where deleting a different field wiped them,
  this is where you'll spot it.
- **Public forms only accept the choices you listed.** If a fan had the form open while
  you changed the choices, they're asked to pick again, and the rest of what they typed
  is still there.
- **Saving the update form can't wipe an old answer.** If a fan's saved answer isn't one
  of your choices anymore, or had stray spaces around it, nothing showed as selected, and
  saving the form erased it. Now it stays on their form until they pick something else,
  and any stray spaces get tidied away when they save.
- **Tick-box answers get checked properly.** A long list of ticks could get past the
  length limit, because only the word "Array" was being measured. If a required group has
  nothing ticked, the error now uses the field's name too.
- **Forms keep your answers after an error.** If the join or update form sends a fan back
  for any reason, like a wrong captcha answer or a taken email, their extra field answers
  are still filled in. Passwords are never kept.
- **Odd form data can't save a member called "Array".** A list sent where one answer
  belongs is refused now.
- **Edit Field leaves a text area alone.** Saving one used to quietly turn it into a
  one-line text field.
- **Approving a fan's update moves Last Updated.** So does a change that goes straight
  through on a listing with no queue, from the update form or Collective Update. Changing
  only a password or whether a website shows doesn't count, and neither does a request that
  didn't go through.
- **Nothing gets saved that your member table can't hold.** Older tables can be narrower
  than the forms allow, and some servers cut a long value short without saying a word,
  which is how an email address ends up with its end missing. The join, update, Collective
  Update, Add Member and Edit Member forms now say it's too long and change nothing. With
  Collective Update, one narrow listing means none of them change, and the same link
  still works once it's fixed.
- **Edit Member has the same name and country limits as the other forms.** That's 100
  characters, and only when you change them, so a long name from an old import won't stop
  you fixing someone's website.
- **A join through an embed gets the right date.** It used your server's time zone instead
  of your Timezone setting, so if the two differ, a fan joining late in the evening could
  show up as joining tomorrow. Joins already saved stay as they are.
- **"Blocked until" is right on the night the clocks go back.** The admin panel could show
  it an hour late. The block itself always ended on time.
- **Codes with long filenames stop piling up.** A code's name only has room for 150
  characters, so a longer filename got cut short, and every time you re-ran a Fanatically
  or BellaBuffs import you got another copy. Codes remember their full filename now, so an
  import finds them again, even if you've renamed them since. If Luster really can't tell
  which code is which, it leaves them alone and tells you why.
- **BellaBuffs imports can update your codes.** There's a new choice on the options page.
  Keeping what's there is still the default, and members and affiliates that already exist
  are always kept.
- **The preview's code numbers match what the import does.** Fanatically and BellaBuffs
  previews now count codes the way the import will, including the ones it'll skip, and say
  why. For BellaBuffs, that includes codes with a missing image.
- **Size labels stay separate in a Fanatically import.** `Small` and `small` used to land
  on one size, which could make two different codes look like one. A label of `0` stays
  its own size too, and a label too long for Luster gets reported instead of cut short.
- **A bad read during a code import costs that one code.** It used to be able to add a
  copy, or put the code under no size. Now that code gets an error and the rest carry on.
- **A hidden website stays hidden.** If you'd hidden a member's website, it came back the
  next time they changed something on the update form, like their name. Now it only changes
  if they remove their website or give a different one. A request from before this update
  might still be in your queue, showing as a change to Showurl, so reject it if you want the
  website kept hidden.
- **Edit Member keeps a country that isn't on the list.** A country from an old import
  showed as "Select Country", and saving the form for any reason erased it. It's offered in
  the dropdown now.
- **You can add a listing on a stricter server.** If your site started on Enthusiast and
  your host uses strict database settings, which is the default on newer servers, Add
  Listing failed with an error page. So did importing a new listing from Fanatically,
  Enthusiast or Listing Admin.
- **A Fanatically import tells you about join emails.** If Fanatically was set not to email
  new members, the import now says Luster still will, and where to turn that off.
- **A little database tidying.** Run the migration page after updating. It removes an old
  setting nothing was using.


### [1.0.0-4] — 2026-11-04

This one has important security fixes, so it's worth updating soon.

**After updating:** go to **Settings → System & Updates** and click **Run
migrations** once. This update makes five small changes to your database (see
below), and the migration page tells you if any of your listings were affected.

**Added:**
- **A closed or adopted-out listing's date can be "unknown".** If you don't
  know when a listing closed or was adopted out, tick **Date unknown** next to
  its Closed On / Adopted On date instead of making one up. Your Owned and Past
  templates can show it with `{if_archived_at_unknown}...{/if_archived_at_unknown}`
  (say, "closed some time ago"), and `{if_archived_at}...{/if_archived_at}`
  shows something only when there is a date. If a listing's date is unknown and
  you untick the box, Luster asks for the date instead of quietly using today.
  This needs the migration step above.
- **You can add a member yourself.** The Members page has a new **Add Member**
  button. Pick the listing, fill in the fan's details and any extra fields,
  and choose whether they're Approved or Pending. Luster makes up a password
  for them, just like the join form does when a fan leaves it blank, and they
  can pick their own later with Reset Password. Turn on **Email Fan?** to send
  them the approval email (Approved) or the join email with their password
  (Pending). It's off unless you switch it on. Closed and Adopted Out listings
  can't take new members, so they aren't offered.

**Changed:**
- **Every new member now waits for your approval.** The "Auto Approve Joins"
  switch on a listing's edit page is gone. When it was on, a fan who joined
  went straight into the member list, which meant anyone could add themselves
  without you looking first. Now all new joins land in the pending queue on
  the Members page, the same as they already did for most listings. Members you
  already approved stay approved. If any of your listings had the switch on,
  the migration page lists them so you know where to look.
- After joining, fans see "Your membership is awaiting approval" instead of
  "may need to be approved", because now it always does. If you customized
  your join success message with an `{if_joined}...{/if_joined}` part (the old
  "you're live" message), that part just won't show anymore. Nothing breaks.
- Fans who leave the password box empty no longer get a second "reset your
  password" email right after joining. Its link only ever worked for
  auto-approved members, so for everyone else it was a dead end. Their join
  email still includes the password Luster made up for them.

**Fixed:**
- **A listing that didn't save doesn't leave new categories behind.** If you
  picked a brand-new category or network on the Add or Edit listing page and
  the save was refused (say, the subject was missing), the new category was
  still created. Now it's only created once the listing actually saves.
- **Re-archiving a listing doesn't erase its notes anymore.** Picking "Archive
  as Closed" or "Archive as Adopted Out" from the bulk menu on a listing that
  was already closed or adopted out wiped its archive note and the adopted-to
  name and link. Now those stay put. Switching an Adopted Out listing to
  Closed still drops the adopted-to details, the same as the edit page does.
- **Previous owner links in capital letters save now.** A link like
  `HTTPS://example.com` is a perfectly good web address, but the listing edit
  page turned it away as "not a valid URL". It's saved just the way you typed
  it now.
- **The approval email fills in the fan's website, country and reset link.**
  If your approval email used `{fan_url}`, `{fan_country}` or `{reset_url}`,
  approving one member with the Approve button left those blank. They're filled
  in now, the same as in the join email. (Approve All and the bulk Approve
  still leave them blank for now.)
- **An old sign-in can't touch someone else's membership anymore.** If a fan was
  signed in to the update form, got removed, and someone else then ended up with
  that same email address, the old sign-in could change the new member's details or
  password, or even delete them. Now a sign-in only works for the member who signed
  in. If anything about that member has changed, they're just asked to sign in
  again. Anyone signed in when you update will have to sign in once more too. Until
  you run the migration step above, a fan whose browser loses its session partway
  through the form also has to sign in again.
- **A faked update form can't skip the security check anymore.** The update form has
  a backup for when a browser loses its session, but just having that backup field
  filled in, with anything at all, let a faked form past the security check. If a
  signed-in fan got tricked into sending one, it could change their details or remove
  them from the listing. Browsers already stop most of these coming from other sites.
  The backup only counts now when there's no sign-in, and it still has to be a real one.
- **Reset links last as long as they say they do.** Luster set when a link would run
  out using one clock and checked it using another, and those two clocks can be set
  to different time zones. So a one-hour reset link could work for hours longer, or
  be dead the moment it arrived. Now both sides use the same clock. This covers fan,
  admin and KIM reset links, Collective Update links, the update form's backup and
  the admin **Remember me** option. Links already sent keep their old time until they
  run out.
- **Going back and forth can't get around a form's attempt limit anymore.** Your public
  forms can be reached two ways: on your own page, and at Luster's own address. If your
  **Timezone** setting and your server's PHP time zone were an hour or more apart, those
  two disagreed about what time it was. Switching between them kept resetting the
  attempt count and could wipe out a block, so on the update form someone could keep
  guessing a fan's password with nothing stopping them. Now every form goes by the same
  clock. Admin login wasn't affected, since there's only one way in. If someone's
  blocked when you update, that one block could end early or run long, and you can
  clear it under **Settings → Spam Filtering**.
- **On Keep In Mind, a password only unlocks the entries that use it.** KIM sign-ups
  don't check the email address belongs to whoever typed it, so someone could sign
  up with a fan's address and, once approved, use their own password to change or
  delete that fan's other entries. Now you only see the entries that use the
  password you signed in with. If you've used different passwords on different
  entries, sign in with each one, or use Forgot Password, which emails a link for
  each approved entry at your address.
- **A KIM reset link stops working when its entry moves to a new email address.**
  The old address's link could still set the moved entry's password.
- **Removing a member takes their waiting update requests and reset links with
  them.** Delete, Merge and Reject all left them behind, so someone new joining at
  that address could have the old request applied to them when you approved it.
  This covers every way of removing a member, and a new member joining at an address
  clears anything left there too.
- **Two saves for the same member wait their turn.** Sign-ins, resets, approvals and
  edits for one email address now happen one at a time, so one can't land halfway
  through another. If one has to wait too long, you get "Please try again in a
  moment" and nothing changes. Emails go out once the save is finished.
- **Approve All on the Members overview checks what the other Approve buttons
  check.** A requested address that was already taken used to end on an error page
  and lose the rest of the batch. It's skipped now, and a changed address brings the
  member's other waiting requests and link check along with it.
- **Deleting an extra field can't delete the wrong thing anymore.** If a listing
  had an extra field named like one of Luster's own member details (say, `name`,
  which an import could leave behind), deleting that field also deleted every
  member's real name. And a field with no column of its own could take another
  field's answers with it. Now deleting a field only ever removes that field's
  own column. If it doesn't have one, Luster removes the field, leaves the column
  alone, and tells you. If Luster can't check, it changes nothing and says so.
- **Deleting an extra field keeps the choices on your other fields.** Deleting
  one field quietly emptied the choices on every other dropdown, radio and
  checkbox field on that listing. If one of those was required, nobody could join
  afterwards. That's fixed, but Luster can't get back choices that were already
  lost, so check your fields and type them in again if any look empty.
- **A field whose column has gone missing can be deleted.** It used to stop with
  an error every time, so you were stuck with it.
- **Adding and deleting extra fields works on the `enth_` table layout.** Both
  stopped with a database error, because they looked for the wrong table.
- **Luster stops you naming a field something that would clash.** That covers
  names like `email`, `added` or `approved` in any mix of capitals, anything
  starting with `additional_`, and a name that only differs in capitals from a
  field you already have (like `Fave` when you have `fave`). Some of these used to
  end on an error page, and others saved fine and then misbehaved. Your existing
  fields aren't touched by this. If the listing's table already has a leftover
  column with the name you picked, Luster says so and asks you to try another.
- **An extra field's answer can't overwrite a member's real details anymore.**
  If a listing had an extra field named like one of Luster's own member details
  (say, `name` or `password`, which an import could leave behind), a form could
  save the answer right over the real thing. A fan could end up with a password
  saved in plain text, a blank address, or could approve themselves. Now every
  form saves an answer only to that field's own column. If a field doesn't have
  one, a blank answer is simply skipped, and a real answer stops the whole save
  with a message, so nothing gets half-saved. The Fields page labels those fields
  so you can see which ones to delete and add again under another name.
- **Edit Member keeps your extra-field changes on the `enth_` table layout.** It
  said "Member updated" and kept the old answers.
- **Every page shows the right answer under the right field.** Sign-up forms, the
  member list on your site, the Members page and the CSV export all read an answer
  the same way forms save it, so a field whose table column is named a little
  differently (like `Fave` and `fave`) shows its answer instead of a blank. Saving
  a blank over it can't happen by accident anymore either.
- **The update queue shows what's really changing.** Email and show-URL changes now
  show their old value next to the new one, and when one address is in two of your
  listings, each request shows that listing's member instead of the other one's.
- **Public forms refuse an answer they can't make sense of.** Sending a list of
  answers to a field that only takes one is an error now, instead of being joined
  together and saved. Tick-box groups work like they always did.
- **Extra fields named with only numbers don't break things anymore.**
  Enthusiast let you name an extra field something like `123`. On the table
  layout without `enth_` in front, a listing with a field like that crashed
  its Members page, Edit Member, the member list on your site and the join
  form. They all work now, and you can add and delete a field like that from
  Additional Fields too (that used to stop with a database error).
- **Joined listings brought over from Fanatically show up in your Random and
  Latest Joined boxes.** The import saved them with an old status name those
  two boxes don't look for. The import uses the right one now, and the
  migration fixes any already saved the old way.
- **Joined listings you've edited show up on their category page again.**
  Saving a joined listing on its edit page quietly lost part of its category,
  so it went missing from any category with a "/" in its name. That's every
  TAFL category ("Anime/Manga: ...") plus a few TFL ones like "Food/Drinks".
  The category dropdown still counted it, so a category could say 18 and only
  show 16. On the "All" page those listings had also slid down near the end.
  The edit page saves categories properly now, and running migrations tidies
  up the ones saved the old way. Joined listings with more than one category
  show up under each of them, not just the first.
- **A joined category's number always matches what you see when you open it.**
  "Cafe" and "Café" (or "cafe") used to show each other's listings, a really
  long category name could pull in listings from a shorter one, and a listing
  picked under the same category twice was counted twice.
- **Saving a joined listing brought over from Fanatically keeps its
  categories.** The edit page didn't recognise how the import labelled TFL and
  TAFL, so saving one wiped its categories. The import labels them properly
  now, and the migration fixes listings you've already imported.
- **Categories the dropdowns don't know about stay put.** If a listing has a
  network or category that isn't in the pickers anymore (like "Custom" from an
  import), the edit page still shows it, and saving keeps it.
- **Blank category rows don't shuffle your categories anymore.** On the joined
  and owned listing pages, leaving a Network & Category row empty above a
  filled one could save a category under the wrong network or drop it. And if
  saving a joined listing fails (say the URL is missing), the form comes back
  with the categories you picked, or none if you'd removed them all.
- **Running a Fanatically import again with "Overwrite" doesn't fail on
  members anymore.** Every member who was already there came back with an
  error. Now they get updated, and anyone who'd hidden their email or website
  keeps it hidden.
- **Fanatically's "lost password" email isn't offered for import anymore.** It
  sends fans a new password, but Luster's reset email sends a link instead, so
  an imported copy said "Your password is:" followed by nothing. The template
  preview now says to copy any wording you like over by hand.
- A Fanatically import now tells you, on the preview and the results page, if
  Fanatically was approving new joins automatically, since Luster won't.
- **Fanatically listings with no members yet get a member list anyway.** A
  listing with nobody in it (or any listing, if you left Members unticked)
  came over with nowhere to keep members and without its custom fields, so its
  join form and member list didn't work. Now every imported listing gets both
  straight away.
- **Custom fields called things like "Email" don't break a Fanatically import
  anymore.** A field named after something every member already has (Email,
  Name, Country...) or two fields whose names come out the same ("a-b" and
  "a b") stopped the import partway through. Now the extra one gets a new name,
  like `email_2`, and the preview and results tell you which ones moved. Luster
  remembers those names, so importing again gives every field the same one,
  even if the fields were reordered or a new one was added. A field you removed
  in Fanatically keeps its answers, and a field you added in Luster yourself is
  never written over: a new Fanatically field with the same name gets its own,
  like `color_2`. If you renamed a field in Fanatically, the answers members
  gave before the rename stay with the old field instead of jumping into the new
  one. Fields named with just numbers (like "123") import fine too. If Luster
  hasn't had the database update that remembers these names, or can't read or
  save them (or can't read its own listings and tables), the import stops before
  changing anything and tells you, instead of guessing.
- **A Fanatically listing can't end up using one of Luster's own tables.** A
  listing named after one (like "owned") could be handed that table to keep its
  members in. Now every table that already exists is off limits, and Luster
  won't change a table that isn't a member list.
- **Two Fanatically listings with the same address share one member list.**
  They become one listing in Luster, but the second one's members used to go
  into a table that listing never reads. Now both sets of members, and both
  listings' custom fields, land in the same place.
- **A listing that moved to a new address in Fanatically stays itself.** With
  Overwrite, a new listing that took its old address used to be poured into it.
  Now each one ends up where it belongs. And if Luster can't save a listing
  partway through, the import stops before the listings after it instead of
  guessing where they go, so you can fix it and import again.
- When you pick **Keep existing**, a listing that's already in Luster keeps its
  own fields and member table exactly as they are. Any values for fields it
  doesn't have are left out, and the results say how many.
- **KIM entries you hadn't approved in Fanatically stay pending.** The import
  used to make every KIM entry public, or every one pending. Now each one keeps
  the status it had in Fanatically, and you can still choose "all pending" or
  "all active" on the Options page.
- **KIM notes aren't lost.** Luster's KIM list has nowhere to keep them, so the
  results page lists every note for you to copy, and they're never shown
  publicly. They aren't saved anywhere in Luster either, so click **Download
  Report** before you leave the results page.
- **An affiliate with a broken list of fanlistings is skipped instead of showing
  on every page.** If Luster couldn't read which fanlistings an affiliate
  belonged to, it used to make it a site-wide affiliate. Now it's skipped and
  listed on the results page so you can add it by hand.
- **Fanatically's collective codes come over.** Codes saved under "Collective"
  used to be skipped. Now they import as collective codes with their size,
  approval and donor, even if you don't import listings, and importing again
  finds them instead of adding copies. Importing again also keeps the images
  Luster already copied for your codes, unless it copies a new one. Codes whose
  names only differ in capital letters (`Code.png` and `code.png`) stay two
  codes, and if Luster has more than one copy of a code and can't tell which
  one to update, it leaves them alone and tells you instead of guessing.
- **Closed and adopted-out Fanatically listings come over as Closed and
  Adopted Out.** They used to land as plain inactive listings, so they never
  showed up in your Past Fanlistings. Now they do. Fanatically doesn't keep
  the date a listing closed or who adopted it, so the date is marked unknown
  (see "Date unknown" above) and you can fill it in yourself. Importing again
  with "Overwrite" keeps the date and note you added, switches Closed to
  Adopted Out (or back) if you changed it in Fanatically, and reopens a
  listing that isn't closed in Fanatically anymore. If Fanatically gives a
  listing a status Luster doesn't recognise, it stays closed with everything you
  added, and the results page says why. "Skip" leaves them alone. If Luster
  can't save a listing's Closed or Adopted Out part, it doesn't save that
  listing at all, so a listing never ends up half-done (showing up as both
  current and past). That works on older databases that started out as
  Enthusiast too: Luster puts the listing back the way it was itself, and in
  the very rare case it can't, the results page tells you exactly what was
  saved. This needs the migration step above. Until it's run, the import tells
  you so before changing anything.
- **Previous owners come over from Fanatically.** If you listed the people who
  ran a listing before you in Fanatically, their names and links now come over
  to that listing's Previous Owners in Luster, in the same order. Importing
  again with "Overwrite" makes them match Fanatically (empty the list there and
  it's emptied here too), and "Skip" leaves the ones a listing already has in
  Luster alone. Two Fanatically listings with the same address share one list.
  A link that isn't a web address, or a name that's too long, is fixed up or
  left out, and the results page tells you. Older Fanatically versions that
  don't have previous owners leave Luster's alone. This needs the migration
  step above; until it's run, the import tells you before changing anything.
  And if something goes wrong partway and Luster can't undo it, the import
  stops right there instead of carrying on with things half-saved.
- **Fanatically listings with no category don't stop the import anymore.** A
  listing with no category failed to import, and every listing after it was
  skipped too. Listings with categories are also saved the same way the
  listing edit page saves them now, so they keep every category, not just the
  first. A listing in lots of categories keeps all of them in Luster; the older
  category field that Enthusiast-style pages read only has room for so many,
  and the results page tells you when a listing has more than fit. If a
  category can't be saved, the results page says so too, instead of the
  category quietly going missing.
- If your Luster database uses the older Enthusiast table layout (`enth_`
  tables), the Fanatically import now says it can't import into that layout
  yet, before changing anything, instead of saving listings where Luster's
  pages don't look.
- **Listing Admin imports found nothing if you left Table Prefix blank**, which
  is exactly what the Options page told you to do. Turns out every Listing
  Admin install has a prefix (`trex_` unless you changed it), so the import was
  looking for tables that weren't there. Now Luster finds your Listing Admin
  tables itself and fills the prefix in for you. If there's more than one
  install in the database, you pick which one. And if the prefix is wrong, it
  tells you which tables it couldn't find instead of showing a preview full of
  zeros.
- **Partial exports work too.** If your SQL file only has some of Listing
  Admin's tables (say, just the ones for codes), type the prefix and untick
  what you don't need. Luster only looks for the tables the ticked steps use.
- **Images from old sites that moved to `https://` come through now.** Lots of
  old scripts saved their image addresses as `http://`, and most sites send
  those to `https://` these days. Luster didn't follow that, so every download
  failed. Now it does, and it still checks every stop along the way. This helps
  every importer, not just Listing Admin.
- In a Listing Admin import, the **Server Path** and **Live Site URL** boxes
  get used even when Listing Admin has its own image settings. If those don't
  work anymore, Luster tries what you typed.
- No more "the Codes add-on was never turned on". If a codes, KIM or updates
  table isn't there, Luster just says so by name, and for an SQL upload
  reminds you to check the export has it. The preview also says images are
  copied automatically instead of "None".
- **A Link Checker scan that fails partway doesn't wipe your results anymore.**
  It used to throw away that listing's earlier check results and leave a
  half-finished list behind. Now a scan either finishes or changes nothing.
- **Members with the same email in different capitals don't break scans.**
  Some older member tables let `Jess@` and `jess@` both join, which made every
  scan of that listing fail. Luster now keeps one (the one with a website, if
  only one has it), skips the other, and lists who was skipped and why. That
  list stays on the page until you dismiss it, and Scan All doesn't reload the
  page on you while there's something to read.
- **Listing Admin's "0000-00-00" dates don't trip up imports anymore.** Some
  old servers saved "no date" that way. Depending on your database, those
  members, joined listings, KIM sign-ups or updates either didn't import at all
  or came over with a nonsense date. Now a member or joined listing with no
  real date just has no date, and a KIM sign-up or update gets the day you
  import it. A listing's "last updated" date also skips over bad member dates
  instead of copying one in.
- **Running the CodeSort import again doesn't pile up extra sizes, donors and
  categories anymore.** Picking "Update matching" skipped the check for ones
  you already had, so every run added another set. Now both choices look for
  what's already there first, and "Update matching" just copies over
  CodeSort's size order. Two donors with the same name but different websites
  stay two donors instead of getting merged, and if you gave a donor an email
  in Luster, the import makes its own instead of using yours.
- **Running the CodeSort import again doesn't add copies of your codes
  either.** Each code now remembers which CodeSort code it came from, so Luster
  finds it again even after its image was copied over under a new name. "Keep
  existing" leaves it alone, and "Update matching" brings its approval, size,
  donor and category up to date without copying the image again. (A code with
  no category in CodeSort keeps the ones you gave it in Luster.) New codes go
  after the ones a listing already has, instead of jumping to the top. Copies
  that older imports already made stay put, so you can delete the extras on the
  Codes page.
- **Codes from an import before this update** don't remember where they came
  from. If they came from this same CodeSort install, tick "Match codes from an
  earlier CodeSort import" on the Options page and Luster links up the ones it
  can pair exactly. Otherwise the preview tells you which ones might get added
  a second time. Old codes whose images were already copied can't be matched
  at all, so the import may add one more copy of those (just once). You can
  delete the older ones on the Codes page.
- If your CodeSort settings don't have a full web address for its images,
  Luster can't tell your install apart from another one, so it asks for a
  **Source ID** on the Options page before importing codes. Sizes, donors and
  categories still come over without one.
- **Codes that end up with no image get listed by name** on the results page,
  along with how to fix them: delete them, then run the import again with a
  working image path, web address or ZIP. Just know that deleting a code also
  removes anything you changed on it in Luster.
- **CodeSort import errors actually show up now.** The results page could say
  "Completed with Errors" without ever saying what went wrong, and the
  downloaded report didn't either. Now each problem is listed, like which code
  or image file it was and why.
- **Listing Admin addresses that start with `//`** (no `http:` or `https:`)
  now get the same Source ID as the full address. So switching between the two
  in your Listing Admin settings, or changing the capitals, doesn't make the
  import think it's a different install and add your codes again. Installs
  you've already imported keep the Source ID they have, so your bridge pages
  keep working. Two catches, and both only matter if the address started with
  `//` back when you imported: if it had capital letters in it and you've
  changed it since, or if you've since taken the `//` off without putting
  `http://` or `https://` in front, type your old Source ID on the Options page
  (it's listed there) before importing again. And if an earlier import already
  saved an install under two Source IDs, Luster now asks you which one to use
  instead of just picking one.


### [1.0.0-3] — 2026-10-30

**After updating:** go to **Settings → System & Updates** and click **Run
migrations** once (next to "Migration state"). This release adds a little
database change that the Listing Admin codes import needs — until you run
it, the import will remind you.

**New:**
- **Listing Admin codes now import:** The Listing Admin import used to leave
  your codes (fan graphics) behind. Now they come over with their images,
  sizes, donors, categories, and approval status, into the right listing.
  Already imported everything else? Run the import again with only Codes
  ticked. You can run it as often as you like without getting copies, and
  if some images didn't make it the first time, running it again (a ZIP of
  your codes folder works well) fills in just those.
- **Source ID for Listing Admin imports:** If you've imported more than one
  Listing Admin install into the same Luster, each one now keeps its own set
  of listing links, so the compatibility bridge never mixes up which
  fanlisting to show. Most people never need to touch this.

**Fixed:**
- The Listing Admin bridge couldn't find your listings on its own after an
  import, so your old `show-join.php`-style pages needed each listing's ID set
  by hand. The import now saves the links the bridge needs. Already imported?
  Run the Listing Admin import again with only Owned listings ticked and Keep
  existing selected — it adds the links without changing anything else. If
  any of your pages pick their listing by hand with `$luster_listing_id`,
  download the bridge again from Admin → Collective first: older copies of
  the bridge let the new links override the listing you picked.
- On brand-new installs, the Link Checker's member check could fail partway
  through — or quietly lose track of members who don't list a website — until
  you ran the migration page once. Fresh installs now set things up correctly
  from the start. (If you've already run the migration page since updating,
  your site already has this fix.)


### [1.0.0-2] — 2026-10-25

A quick fix release.

**Fixed:**
- On some web hosts, pages with a form or anything else that needs to
  remember visitors between page loads — join, members, update, reset,
  contact, affiliates, wishlist, KIM, codes, adoption, and join rules — would
  just stop partway down, with nothing after the header showing up. Owned,
  joined, stats, and news pages were fine, which made it extra confusing.
  These now work everywhere. You don't need to change anything on your site:
  the one-time setup line you already pasted at the top of your header
  takes care of it after you update.
- If that setup line is missing (or has some HTML above it), those pages now
  show a short message explaining what to fix, instead of a half-blank page.


### [1.0.0-1] — 2026-10-25

A mix of new stuff, bug fixes, and a round of security hardening since 1.0.0.

**New:**
- **Previous Owners & Past Fanlistings:** Credit past owners on a listing (handy
  after an adoption), and a merged "Past Fanlistings" view that combines Closed
  and Adopted Out listings into one list.
- **Smarter auto-updater:** Updates now clean up old files that aren't part of
  the new release, keep `vendor/` current automatically, and won't silently
  overwrite a customized `public/.htaccess` — you'll get a copy of the new
  default to compare instead.
- **News Feed formatting:** Entries now support a small set of safe HTML tags
  (bold, italics, links, etc.) instead of plain text only.
- **Hidden image paths:** Uploaded images no longer reveal your install's
  folder structure in their URLs, same protection your CSS already had.

**Fixed:**
- An internal security audit turned up and patched several issues — SQL
  injection, SSRF, open redirects, and a couple of race conditions.
- The stats embed could go blank on archived (Closed/Adopted Out) listings,
  and the join rules gate could fail to actually save someone's agreement.
- Link Checker accuracy fixes: false "dead" results on dual-stack hosts,
  missed backlinks on two-level category-picker pages, silently skipped
  approved members with no URL, and results ignoring your configured date
  format.
- CodeSort image migration wasn't actually triggering during import.
- Merging duplicate members could lose the earlier join date.
- Drag-to-reorder on Codes wasn't saving.
- Affiliate approve/reject could lose its scope filter, and the
  affiliates/codes style toggle could silently override your global default.

**Polish:**
- Default theme colors reworked to meet WCAG AA contrast, plus better
  contrast on toasts and form hints.
- A wording pass over the admin panel — clearer copy, tighter forms, new
  tooltips.
- Dashboard's "At a Glance" card redone, and a lot of duplicate/dead CSS
  cleaned up under the hood.


### [1.0.0] — 2026-07-14

The first full release of Luster! A free, open-source management script
for fanlistings, cliques, collectives, and other hobby listings. If
you've used scripts like Enthusiast before, Luster should feel familiar
but with a modern foundation: one admin panel for everything, security
built in from the start, and a setup wizard that does the heavy lifting
for you.

**Highlights:**

- **One admin panel, multiple listings & types:** Run a single listing or a
  whole collective side by side.
- **Guided migration & wrappers:** Import Data Wizard plus compatibility
  wrappers for Enthusiast, Listing Admin, CodeSort, and Fanatically, so old
  includes keep working while you transition.
- **Dashboard to-do list:** Pending members, overdue listings, affiliate
  applications, and more, with shortcuts to handle each one.
- **Collective-wide member updates:** Members who've joined more than one of
  your fanlistings can update their info everywhere at once.
- **Multiple admin users:** Separate logins for co-owners instead of one
  shared password.
- **Admin panel customization:** Light/dark mode and five bundled skins,
  with community skin support in development.
- **Custom networks & categories:** Built-in TFL/TAFL categories, or add
  your own, plus a one-click TFL/TAFL approval URL lookup.
- **Customizable public embeds & live previews:** Drop-in embeds with
  clickable tokens, an Embed Wizard, and targetable CSS classes.
- **Three-tier template system:** Global defaults, per-listing overrides,
  one-click reset.
- **Template portability:** Export/import template packs, copy templates
  between listings, and roll back with built-in revision history.
- **Drag-and-drop reordering:** For codes and affiliates, reflected live
  on your site.
- **Communication tools with live previews:** Bulk or individual emails,
  reusable templates, per-listing on/off control.
- **Extra features when you want them:** Five optional add-ons — News
  Feed, Wishlist, Keep In Mind, Adoptions, and Join Rules.
- **Link Checker & maintenance tools:** Dead-link/redirect checks,
  backlink verification, one-click URL updates, activity and error logs.
- **Security-first foundations:** Argon2id passwords, CSRF protection,
  prepared statements, rate limiting, and 1,760+ automated tests.