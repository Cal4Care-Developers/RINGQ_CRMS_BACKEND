#!/usr/bin/perl
# Adds (or with --remove, takes out) the ring push hook where directory.lua writes an
# extension's dial-string. The hook asks ringq_ring_dial.lua which dial-string to use and, on
# any error, keeps the original one.
use strict;
use warnings;

my $remove = $ARGV[0] && $ARGV[0] eq '--remove' ? shift @ARGV : undef;
my $file = shift @ARGV or die "usage: $0 [--remove] directory.lua\n";

local $/;
open my $in, '<', $file or die "$file: $!\n";
my $src = <$in>;
close $in;

my $n;
if ($remove) {
	$n = ($src =~ s{^([ \t]*)-- \[ringq-ring\] begin\n.*?^[ \t]*-- \[ringq-ring\] end\n}{
		"$1xml:append([[" . "\t\t\t\t\t\t\t\t<param name=\"dial-string\" value=\"]] .. dial_string .. [[\"/>]]);\n"
	}gmse);
} else {
	$n = ($src =~ s{^([ \t]*)xml:append\(\[\[(\s*)<param name="dial-string" value="\]\] \.\. dial_string \.\. \[\["/>\]\]\);\n}{
		my ($i, $w) = ($1, $2);
		"$i-- [ringq-ring] begin\n"
		. "${i}local rq_ok, rq_dial, rq_orig = pcall(function() return require(\"ringq_ring_dial\").wrap(dial_string, sip_from_user, domain_name, dbh, domain_uuid) end)\n"
		. "${i}if not rq_ok or not rq_dial then rq_dial, rq_orig = dial_string, nil end\n"
		. "${i}xml:append([[$w<param name=\"dial-string\" value=\"]] .. rq_dial .. [[\"/>]]);\n"
		. "${i}if rq_orig then xml:append([[$w<param name=\"ringq-orig-dial-string\" value=\"]] .. rq_orig .. [[\"/>]]); end\n"
		. "$i-- [ringq-ring] end\n"
	}gme);
}

die "no dial-string line found in $file\n" unless $n;
open my $out, '>', $file or die "$file: $!\n";
print $out $src;
close $out;
print "directory.lua: ", ($remove ? "removed" : "added"), " $n hook(s)\n";
